<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

define('CHESS_AUTH_LIB', true);
require __DIR__ . '/chess_auth.php';   // stellt current_user() bereit

$dir = __DIR__ . '/online_games';
if (!is_dir($dir)) @mkdir($dir, 0755, true);

function out($data, $code = 200) {
  // Spieler-Tokens NIE an den Client: der Gast bekam sonst ueber join/poll den
  // Host-Token mit und konnte fuer beide Seiten ziehen. Der Client braucht nur
  // color/name; seinen eigenen Token kennt er aus create bzw. dem Einladungslink.
  if (isset($data['game']['players']) && is_array($data['game']['players'])) {
    foreach ($data['game']['players'] as $r => $p) {
      if (is_array($p)) unset($data['game']['players'][$r]['token']);
    }
  }
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}
function clean_id($id) {
  return preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id);
}
// (string)-Cast ist wichtig: bei {"token":[]} liefert preg_replace() ein ARRAY zurueck,
// hash_equals(string, array) wirft dann eine TypeError -> HTTP 500 + Eintrag im Error-Log
// (als Log-Flooder nutzbar). Gleiches gilt fuer 'move'.
function clean_token($t) {
  return preg_replace('/[^a-f0-9]/', '', (string)$t);
}
// Startstellung: vorher wurde nur count()===64 geprueft, NICHT der Inhalt. Jedes Feld
// durfte ein beliebig langer String oder ein verschachteltes Array sein -> eine
// Partiedatei konnte bis post_max_size (Standard 8 MB) gross werden, x 100 Partien.
function clean_board($b) {
  if (!is_array($b) || count($b) !== 64) return null;
  $ok = ['', 'p','n','b','r','q','k', 'P','N','B','R','Q','K'];
  $out = [];
  foreach (array_values($b) as $v) {
    if (!is_scalar($v) && $v !== null) return null;
    $v = (string)$v;
    if (!in_array($v, $ok, true)) return null;
    $out[] = $v;
  }
  return $out;
}
function game_file($id) {
  global $dir;
  return $dir . '/' . clean_id($id) . '.json';
}
function read_input() {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw ?: '{}', true);
  return is_array($data) ? $data : [];
}
// ---- Sperre pro Partie -------------------------------------------------------
// Ohne Sperre ist der Zyklus load_game() -> aendern -> save_game() nicht atomar:
// Poll (alle 1,2 s pro Spieler) und move koennen sich ueberschreiben -> Zug verloren,
// danach "Out of sync". Das frueher benutzte LOCK_EX in file_put_contents() hat nichts
// bewirkt, weil es die tmp-Datei mit uniqid()-Namen gesperrt hat, nicht die Zieldatei.
// Die Sperre wird bei Prozessende ohnehin vom Kernel freigegeben (wichtig, weil out()
// per exit() aussteigt und dann kein finally mehr laeuft).
$GLOBALS['chess_lock'] = null;
function lock_game_file($file) {
  if ($GLOBALS['chess_lock']) return;                 // schon gesperrt
  $fh = fopen($file . '.lock', 'c');
  if ($fh === false) out(['error' => 'Lock failed'], 500);
  if (!flock($fh, LOCK_EX)) { fclose($fh); out(['error' => 'Busy'], 503); }
  $GLOBALS['chess_lock'] = $fh;
}
function unlock_game_file() {
  if (!$GLOBALS['chess_lock']) return;
  flock($GLOBALS['chess_lock'], LOCK_UN);
  fclose($GLOBALS['chess_lock']);
  $GLOBALS['chess_lock'] = null;
}
function load_game($id, $lock = true) {
  $file = game_file($id);
  if (!$id || !file_exists($file)) out(['error' => 'Game not found'], 404);
  if ($lock) lock_game_file($file);                   // ab hier exklusiv
  $raw = @file_get_contents($file);
  $game = json_decode($raw !== false ? ($raw ?: '{}') : '{}', true);
  // Strukturpruefung: eine leere/abgeschnittene Datei kommt sonst als [] durch und
  // laesst spaeter $game['players']['guest']['token'] mit einer TypeError knallen.
  if (!is_array($game) || !isset($game['players']['host']['token'], $game['players']['guest']['token'])) {
    out(['error' => 'Invalid game'], 500);
  }
  return [$file, $game];
}
function save_game($file, $game) {
  $json = json_encode($game, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) out(['error' => 'Encode failed'], 500);
  $tmp = $file . '.tmp.' . uniqid('', true);
  // Byte-Anzahl vergleichen: bei voller Platte schreibt file_put_contents TEILWEISE
  // und liefert trotzdem nicht false — eine abgeschnittene JSON darf niemals
  // per rename() zur Produktivdatei werden.
  $n = file_put_contents($tmp, $json);
  if ($n === false || $n !== strlen($json)) { @unlink($tmp); out(['error' => 'Write failed'], 500); }
  if (!rename($tmp, $file)) { @unlink($tmp); out(['error' => 'Rename failed'], 500); }
  unlock_game_file();
}
function token() {
  return bin2hex(random_bytes(12));
}
function cleanup_games() {
  global $dir;
  foreach (glob($dir . '/*.json') ?: [] as $file) {
    $age = time() - filemtime($file);
    $g = json_decode(@file_get_contents($file) ?: '{}', true);
    $status = is_array($g) ? ($g['status'] ?? '') : '';
    // Wartende Spiele nach 2 Stunden, beendete nach 6 Stunden, alles andere nach 24 Stunden
    $maxAge = ($status === 'waiting') ? 7200 : (($status === 'finished') ? 21600 : 86400);
    if ($age > $maxAge) { @unlink($file); @unlink($file . '.lock'); }
  }
  // Reste abgebrochener Schreibvorgaenge und verwaiste Sperrdateien (sammelten sich
  // vorher unbegrenzt an, weil glob('*.json') sie nicht erfasst)
  foreach (glob($dir . '/*.tmp.*') ?: [] as $f) if (time() - filemtime($f) > 300) @unlink($f);
  foreach (glob($dir . '/*.lock') ?: [] as $f) {
    if (!file_exists(substr($f, 0, -5))) @unlink($f);   // ".lock" ohne zugehoerige Partie
  }
}
// Anzahl laufender Partien — mit kurzem Cache. Vorher wurde bei JEDEM Request
// (auch beim Poll alle 1,2 s) jede Partie gelesen und geparst; der ungeschuetzte
// sessions-Endpoint machte denselben Scan ein zweites Mal. Das war der
// realistischste Weg, den Server per Request-Flut lahmzulegen.
function count_active_games($ttl = 15) {
  global $dir;
  $cache = $dir . '/count.dat';   // absichtlich NICHT .json (sonst im glob der Partien)
  $c = json_decode(@file_get_contents($cache) ?: '{}', true);
  if (is_array($c) && isset($c['n'], $c['t']) && (time() - intval($c['t'])) < $ttl) return intval($c['n']);
  $active = 0;
  foreach (glob($dir . '/*.json') ?: [] as $f) {
    $g = json_decode(@file_get_contents($f) ?: '{}', true);
    if (is_array($g) && ($g['status'] ?? '') !== 'finished') $active++;
  }
  @file_put_contents($cache, json_encode(['n' => $active, 't' => time()]));
  return $active;
}
function now_ms() {
  return (int) round(microtime(true) * 1000);
}
// Zeitniederlage der Seite am Zug feststellen — in JEDEM schreibenden Endpunkt, sonst
// konnte z. B. ein angenommenes Remis oder ein Ergebnis eine abgelaufene Uhr ueberholen.
// true = Partie wurde gerade beendet ($game geaendert, muss gespeichert werden).
function flag_fall(&$game, $now) {
  if (($game['status'] ?? '') !== 'playing' || intval($game['timeMode'] ?? 0) <= 0 || !isset($game['turnStart'])) return false;
  $side = count($game['moves']) % 2 === 0 ? 'w' : 'b';
  if (intval($game['clock'][$side] ?? 0) - ($now - intval($game['turnStart'])) > 0) return false;
  $game['clock'][$side] = 0;
  $game['status'] = 'finished';
  $game['result'] = 'Zeit abgelaufen — ' . ($side === 'w' ? 'Schwarz' : 'Weiß') . ' gewinnt';
  $game['drawOffer'] = null;
  $game['updated'] = time();
  return true;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
// Aufraeumen liest alle Partien -> nicht bei jedem Request. Beim Anlegen einer Partie
// immer, sonst nur in ~1 % der Aufrufe (reicht bei 2 Spielern voellig).
if ($action === 'create' || mt_rand(1, 100) === 1) cleanup_games();
$input = $method === 'POST' ? read_input() : [];

if ($method === 'POST' && $action === 'create') {
  $authUser = current_user();
  if (!$authUser) out(['error' => 'Login erforderlich', 'auth' => true], 401);
  $now = now_ms();
  // Max. 100 gleichzeitig laufende Partien (Schutz gegen Platten-/Inode-Fill).
  // Stale Spiele wurden oben in cleanup_games() bereits entfernt.
  // TTL 0 = hier immer frisch zaehlen, das Limit muss stimmen.
  if (count_active_games(0) >= 100) out(['error' => 'Zu viele laufende Partien', 'limit' => true], 429);

  $id = strtolower(bin2hex(random_bytes(4)));
  $hostToken = token();
  $guestToken = token();
  $hostColor = ($input['hostColor'] ?? 'w') === 'b' ? 'b' : 'w';
  $guestColor = $hostColor === 'w' ? 'b' : 'w';
  $game = [
    'id' => $id,
    'created' => time(),
    'updated' => time(),
    'status' => 'waiting',
    'positionMode' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['positionMode'] ?? 'standard')),
    'timeMode' => intval($input['timeMode'] ?? 0),
    'incrementMode' => intval($input['incrementMode'] ?? 0),
    'clock' => ['w' => intval($input['timeMode'] ?? 0) * 1000, 'b' => intval($input['timeMode'] ?? 0) * 1000],
    'turnStart' => null,
    'board' => clean_board($input['board'] ?? null),
    'players' => [
      'host' => ['token' => $hostToken, 'color' => $hostColor, 'joined' => time(), 'seen' => time(), 'name' => $authUser],
      'guest' => ['token' => $guestToken, 'color' => $guestColor, 'joined' => null, 'seen' => null, 'name' => null]
    ],
    'moves' => [],
    'result' => null,
    'round' => 1,
    'rematch' => ['host' => false, 'guest' => false],
    'drawOffer' => null
  ];
  save_game(game_file($id), $game);
  out(['ok' => true, 'gameId' => $id, 'token' => $hostToken, 'color' => $hostColor, 'joinToken' => $guestToken, 'now' => $now]);
}

if ($method === 'POST' && $action === 'join') {
  $authUser = current_user();
  if (!$authUser) out(['error' => 'Login erforderlich', 'auth' => true], 401);
  $now = now_ms();
  $id = clean_id($input['gameId'] ?? '');
  $tokenIn = clean_token($input['token'] ?? '');
  [$file, $game] = load_game($id);
  if (!hash_equals($game['players']['guest']['token'], $tokenIn)) out(['error' => 'Invalid invitation'], 403);
  // Einladung ist an das Konto gebunden, das zuerst beigetreten ist
  $guestName = $game['players']['guest']['name'] ?? null;
  if ($guestName && $guestName !== $authUser) out(['error' => 'Einladung bereits angenommen'], 403);
  $game['players']['guest']['joined'] = $game['players']['guest']['joined'] ?: time();
  $game['players']['guest']['seen'] = time();
  $game['players']['guest']['name'] = $guestName ?: $authUser;
  // Nur eine WARTENDE Partie starten. Vorher machte ein erneutes join (Gast laedt nach
  // Partieende die Einladungsseite neu) aus einer beendeten wieder eine laufende Partie.
  if ($game['status'] === 'waiting') {
    $game['status'] = 'playing';
    if (!isset($game['turnStart']) || $game['turnStart'] === null) $game['turnStart'] = $now;
  }
  $game['updated'] = time();
  save_game($file, $game);
  out(['ok' => true, 'game' => $game, 'color' => $game['players']['guest']['color'], 'now' => $now]);
}

if ($method === 'GET' && $action === 'poll') {
  $now = now_ms();
  $id = clean_id($_GET['gameId'] ?? '');
  $tokenIn = clean_token($_GET['token'] ?? '');
  [$file, $game] = load_game($id);
  $role = null;
  foreach (['host','guest'] as $r) if (hash_equals($game['players'][$r]['token'], $tokenIn)) $role = $r;
  if (!$role) out(['error' => 'Invalid token'], 403);
  // Nur bei echten Änderungen schreiben (Poll läuft alle 1,2 s pro Spieler)
  $dirty = flag_fall($game, $now);
  // "seen" nur alle 30 s persistieren — hält auch filemtime für cleanup_games() frisch
  if (time() - intval($game['players'][$role]['seen'] ?? 0) >= 30) {
    $game['players'][$role]['seen'] = time();
    $dirty = true;
  }
  if ($dirty) {
    $game['updated'] = time();
    save_game($file, $game);          // gibt die Sperre frei
  } else {
    unlock_game_file();               // nichts zu schreiben -> Sperre sofort freigeben
  }
  out(['ok' => true, 'role' => $role, 'color' => $game['players'][$role]['color'], 'game' => $game, 'now' => $now]);
}

if ($method === 'POST' && $action === 'move') {
  $now = now_ms();
  $id = clean_id($input['gameId'] ?? '');
  $tokenIn = clean_token($input['token'] ?? '');
  $uci = preg_replace('/[^a-h1-8qrbnk]/', '', strtolower((string)($input['move'] ?? '')));
  [$file, $game] = load_game($id);
  $role = null;
  foreach (['host','guest'] as $r) if (hash_equals($game['players'][$r]['token'], $tokenIn)) $role = $r;
  if (!$role) out(['error' => 'Invalid token'], 403);
  // Nur in einer LAUFENDEN Partie ziehen. Vorher liessen sich Zuege auch an eine
  // wartende (Gast noch nicht beigetreten) oder bereits beendete Partie anhaengen.
  if (($game['status'] ?? '') !== 'playing') out(['error' => 'Spiel nicht aktiv', 'game' => $game], 409);
  if (!preg_match('/^[a-h][1-8][a-h][1-8][qrbnk]?$/', $uci)) out(['error' => 'Invalid move'], 400);
  if (count($game['moves']) !== intval($input['ply'] ?? -1)) out(['error' => 'Out of sync', 'game' => $game], 409);
  if (count($game['moves']) >= 600) out(['error' => 'Zu viele Zuege'], 400);   // Obergrenze
  $movingColor = count($game['moves']) % 2 === 0 ? 'w' : 'b';
  // Nur fuer die EIGENE Farbe ziehen. Vorher wurde $role ermittelt und $movingColor
  // berechnet, aber nie verglichen — jede Seite konnte die Zuege der anderen einreichen.
  if (($game['players'][$role]['color'] ?? '') !== $movingColor) out(['error' => 'Nicht am Zug'], 403);
  if (intval($game['timeMode'] ?? 0) > 0 && isset($game['turnStart'])) {
    $elapsed = $now - intval($game['turnStart']);
    $remaining = intval($game['clock'][$movingColor] ?? 0) - $elapsed;
    // Zeit bereits abgelaufen -> Partie beenden statt den Zug anzunehmen.
    // Vorher setzte max(0,...) die Uhr auf 0 und die Gutschrift machte sie wieder
    // positiv: wer zog, bevor der Gegner das naechste Mal pollte, holte seine Zeit
    // zurueck. Ob die Fahne fiel, entschied ein Rennen zwischen poll und move.
    if ($remaining <= 0) {
      $game['clock'][$movingColor] = 0;
      $game['status'] = 'finished';
      $game['result'] = 'Zeit abgelaufen — ' . ($movingColor === 'w' ? 'Schwarz' : 'Weiß') . ' gewinnt';
      $game['drawOffer'] = null;
      $game['updated'] = time();
      save_game($file, $game);
      out(['error' => 'Zeit abgelaufen', 'game' => $game, 'now' => $now], 409);
    }
    $game['clock'][$movingColor] = $remaining + intval($game['incrementMode'] ?? 0) * 1000;
    // Bis einschl. 5 Min: +1 Sek. Bonus pro Zug (wie gegen den Computer)
    if (intval($game['timeMode'] ?? 0) <= 300) $game['clock'][$movingColor] += 1000;
  }
  $game['moves'][] = $uci;
  $game['drawOffer'] = null;   // ein Zug zieht ein offenes Remis-Angebot zurück
  if (intval($game['timeMode'] ?? 0) > 0) $game['turnStart'] = $now;
  $game['updated'] = time();
  save_game($file, $game);
  out(['ok' => true, 'game' => $game, 'now' => $now]);
}

if ($method === 'POST' && $action === 'result') {
  $id = clean_id($input['gameId'] ?? '');
  $tokenIn = clean_token($input['token'] ?? '');
  [$file, $game] = load_game($id);
  $valid = false;
  foreach (['host','guest'] as $r) if (hash_equals($game['players'][$r]['token'], $tokenIn)) $valid = true;
  if (!$valid) out(['error' => 'Invalid token'], 403);
  // Ein bereits feststehendes Ergebnis nicht ueberschreiben (z.B. Zeitniederlage,
  // die der Server selbst erkannt hat) und in einer wartenden Partie kein Ergebnis
  // setzen. Vorher war 'result' in jedem Zustand von beiden Seiten setzbar.
  if (($game['status'] ?? '') !== 'playing') {
    unlock_game_file();
    out(['ok' => true, 'game' => $game]);
  }
  if (flag_fall($game, now_ms())) { save_game($file, $game); out(['ok' => true, 'game' => $game]); }
  $game['result'] = mb_substr((string)($input['result'] ?? ''), 0, 120);
  $game['status'] = 'finished';
  $game['drawOffer'] = null;
  $game['updated'] = time();
  save_game($file, $game);
  out(['ok' => true, 'game' => $game]);
}

if ($method === 'POST' && $action === 'rematch') {
  $now = now_ms();
  $id = clean_id($input['gameId'] ?? '');
  $tokenIn = clean_token($input['token'] ?? '');
  [$file, $game] = load_game($id);
  $role = null;
  foreach (['host','guest'] as $r) if (hash_equals($game['players'][$r]['token'], $tokenIn)) $role = $r;
  if (!$role) out(['error' => 'Invalid token'], 403);
  if ($game['status'] !== 'finished') out(['error' => 'Spiel noch nicht beendet'], 409);

  $game['rematch'][$role] = true;

  // Wenn BEIDE zustimmen -> Partie zurücksetzen, neue Runde
  if ($game['rematch']['host'] && $game['rematch']['guest']) {
    $game['moves'] = [];
    $game['result'] = null;
    $game['status'] = 'playing';
    $game['round'] = ($game['round'] ?? 1) + 1;
    $game['rematch'] = ['host' => false, 'guest' => false];
    $game['drawOffer'] = null;
    $limit = intval($game['timeMode'] ?? 0) * 1000;
    $game['clock'] = ['w' => $limit, 'b' => $limit];
    $game['turnStart'] = $now;
    // Farben tauschen
    $hc = $game['players']['host']['color'];
    $gc = $game['players']['guest']['color'];
    $game['players']['host']['color'] = $gc;
    $game['players']['guest']['color'] = $hc;
  }

  $game['updated'] = time();
  save_game($file, $game);
  out(['ok' => true, 'game' => $game, 'now' => $now]);
}

if ($method === 'POST' && $action === 'offerdraw') {
  $id = clean_id($input['gameId'] ?? '');
  $tokenIn = clean_token($input['token'] ?? '');
  [$file, $game] = load_game($id);
  $role = null;
  foreach (['host','guest'] as $r) if (hash_equals($game['players'][$r]['token'], $tokenIn)) $role = $r;
  if (!$role) out(['error' => 'Invalid token'], 403);
  if (($game['status'] ?? '') !== 'playing') out(['error' => 'Spiel nicht aktiv'], 409);
  if (flag_fall($game, now_ms())) { save_game($file, $game); out(['ok' => true, 'game' => $game]); }
  if (($game['drawOffer'] ?? null) && $game['drawOffer'] !== $role) {
    // Beide haben angeboten -> Einigung
    $game['result'] = 'Remis (Einigung)';
    $game['status'] = 'finished';
    $game['drawOffer'] = null;
  } else {
    $game['drawOffer'] = $role;
  }
  $game['updated'] = time();
  save_game($file, $game);
  out(['ok' => true, 'game' => $game]);
}

if ($method === 'POST' && $action === 'responddraw') {
  $id = clean_id($input['gameId'] ?? '');
  $tokenIn = clean_token($input['token'] ?? '');
  [$file, $game] = load_game($id);
  $role = null;
  foreach (['host','guest'] as $r) if (hash_equals($game['players'][$r]['token'], $tokenIn)) $role = $r;
  if (!$role) out(['error' => 'Invalid token'], 403);
  // Beendete Partie (z. B. Zeitniederlage) darf ein Remis nicht mehr ueberschreiben
  if (($game['status'] ?? '') !== 'playing') { unlock_game_file(); out(['ok' => true, 'game' => $game]); }
  if (flag_fall($game, now_ms())) { save_game($file, $game); out(['ok' => true, 'game' => $game]); }
  $offer = $game['drawOffer'] ?? null;
  $accept = !empty($input['accept']);
  // nur auf ein Angebot des GEGNERS reagieren
  if ($offer && $offer !== $role) {
    if ($accept) {
      $game['result'] = 'Remis (Einigung)';
      $game['status'] = 'finished';
    }
    $game['drawOffer'] = null;
    $game['updated'] = time();
    save_game($file, $game);
  } else {
    unlock_game_file();   // kein (fremdes) Angebot offen -> nichts zu schreiben
  }
  out(['ok' => true, 'game' => $game]);
}

if ($method === 'GET' && $action === 'sessions') {
  // Gecacht (15 s): dieser Endpunkt braucht kein Login und war damit ein
  // billiger Verstaerker fuer Request-Fluten.
  out(['ok' => true, 'sessions' => count_active_games(15)]);
}

out(['error' => 'Unknown action'], 400);
