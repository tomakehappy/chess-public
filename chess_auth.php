<?php
// Spielerkonten für das Schachspiel — Registrieren/Login/Logout/Whoami.
// Daten liegen in auth_data/ (per nginx gesperrt). Kein E-Mail, kein Reset:
// Passwort vergessen => Eintrag in users.json löschen, neu registrieren.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('AUTH_DIR', __DIR__ . '/auth_data');
define('SESS_COOKIE', 'chess_sess');
define('SESS_TTL', 30 * 86400);        // Session: 30 Tage
define('RATE_MAX', 10);                // max. Versuche ...
define('RATE_WINDOW', 900);            // ... pro 15 Minuten und IP

function auth_out($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json');
  header('Cache-Control: no-cache, no-store, must-revalidate');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}
// Sperre fuer die Kontodateien. Ohne sie kann ein gleichzeitiges login (schreibt
// lastLogin) und settings einen Nutzereintrag verlieren, und parallele Loginversuche
// zaehlen sich im Rate-Limit gegenseitig weg. Kernel gibt die Sperre bei Prozessende frei.
$GLOBALS['auth_locks'] = [];
function auth_lock($file) {
  if (isset($GLOBALS['auth_locks'][$file])) return;
  if (!is_dir(AUTH_DIR)) @mkdir(AUTH_DIR, 0700, true);
  $fh = fopen($file . '.lock', 'c');
  if ($fh === false) auth_out(['error' => 'Lock failed'], 500);
  if (!flock($fh, LOCK_EX)) { fclose($fh); auth_out(['error' => 'Busy'], 503); }
  $GLOBALS['auth_locks'][$file] = $fh;
}
function auth_unlock($file) {
  if (!isset($GLOBALS['auth_locks'][$file])) return;
  flock($GLOBALS['auth_locks'][$file], LOCK_UN);
  fclose($GLOBALS['auth_locks'][$file]);
  unset($GLOBALS['auth_locks'][$file]);
}
// WICHTIG: unterscheidet "Datei fehlt" (-> Default) von "Datei da, aber unlesbar".
// Vorher galt korruptes JSON als leer — dann scheitern alle Logins UND die naechste
// Registrierung ueberschreibt users.json mit einem einzigen Nutzer: alle Konten weg.
function auth_read_json($file, $def, $strict = false) {
  if (!is_file($file)) return $def;
  $raw = @file_get_contents($file);
  if ($raw === false) {
    if ($strict) auth_out(['error' => 'Kontodaten nicht lesbar'], 500);
    return $def;
  }
  if (trim($raw) === '') return $def;                 // leere Datei = noch keine Daten
  $d = json_decode($raw, true);
  if (!is_array($d)) {
    // Beschaedigt: NICHT als leer weiterarbeiten, sonst Datenverlust.
    if ($strict) auth_out(['error' => 'Kontodaten beschaedigt'], 500);
    return $def;
  }
  return $d;
}
function auth_write_json($file, $data) {
  if (!is_dir(AUTH_DIR)) @mkdir(AUTH_DIR, 0700, true);
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) auth_out(['error' => 'Encode failed'], 500);
  // Backup des letzten guten Stands, bevor ueberschrieben wird
  if (is_file($file) && filesize($file) > 2) @copy($file, $file . '.bak');
  $tmp = $file . '.tmp.' . uniqid('', true);
  // Byte-Anzahl pruefen: bei voller Platte/Quota schreibt file_put_contents TEILWEISE
  // und liefert trotzdem nicht false. Eine abgeschnittene JSON darf nie per rename()
  // zur Produktivdatei werden.
  $n = file_put_contents($tmp, $json);
  if ($n === false || $n !== strlen($json)) { @unlink($tmp); auth_out(['error' => 'Write failed'], 500); }
  if (!rename($tmp, $file)) { @unlink($tmp); auth_out(['error' => 'Rename failed'], 500); }
  auth_unlock($file);
  // Reste abgebrochener Schreibvorgaenge aufraeumen (sammelten sich sonst an und
  // enthalten Hashes/Tokens)
  foreach (glob(AUTH_DIR . '/*.tmp.*') ?: [] as $f) if (time() - filemtime($f) > 300) @unlink($f);
}
function auth_config() {
  $f = AUTH_DIR . '/config.php';
  if (is_file($f)) { $c = include $f; if (is_array($c)) return $c; }
  return ['invite' => null];
}
function auth_cookie_path() {
  $p = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
  return ($p === '' || $p === '\\') ? '/' : $p;
}
// Laeuft die Anfrage ueber HTTPS? Hinter nginx im Docker-Container endet TLS im Proxy,
// $_SERVER['HTTPS'] ist dann NICHT gesetzt — deshalb zusaetzlich die Proxy-Header pruefen.
function auth_is_https() {
  if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
  if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
  if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
  if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
  return false;
}
function auth_set_cookie($token, $expires) {
  // Die Seite ist HTTPS-only. Secure daher hart setzen, sonst geht das 30-Tage-Session-
  // Cookie bei einem http-Aufruf (z.B. vor dem 80->443-Redirect) im Klartext mit.
  // Nur bei echtem lokalem HTTP-Test (localhost) wird Secure weggelassen, weil das
  // Cookie dort sonst gar nicht gesetzt werden koennte.
  $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
  $isLocal = (strpos($host, 'localhost') === 0 || strpos($host, '127.0.0.1') === 0);
  setcookie(SESS_COOKIE, $token, [
    'expires' => $expires,
    'path' => auth_cookie_path(),
    'secure' => $isLocal ? auth_is_https() : true,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}
function auth_sessions() {
  $file = AUTH_DIR . '/sessions.json';
  $s = auth_read_json($file, []);
  $now = time(); $expired = false;
  foreach ($s as $t => $v) { if (($v['exp'] ?? 0) < $now) { $expired = true; break; } }
  if (!$expired) return $s;                 // Normalfall: nur lesen, keine Sperre noetig
  auth_lock($file);                         // aufraeumen -> unter Sperre neu lesen
  $s = auth_read_json($file, []);
  foreach ($s as $t => $v) { if (($v['exp'] ?? 0) < $now) unset($s[$t]); }
  auth_write_json($file, $s);               // gibt die Sperre frei
  return $s;
}
function auth_new_session($userKey) {
  $file = AUTH_DIR . '/sessions.json';
  auth_lock($file);                         // Sperre VOR dem Lesen: sonst kann ein
  $s = auth_read_json($file, []);           // paralleler Login eine Session verlieren
  $now = time();
  foreach ($s as $t => $v) { if (($v['exp'] ?? 0) < $now) unset($s[$t]); }
  $t = bin2hex(random_bytes(24));
  $s[$t] = ['user' => $userKey, 'exp' => time() + SESS_TTL];
  auth_write_json($file, $s);
  auth_set_cookie($t, time() + SESS_TTL);
  return $t;
}
// users.json-Schlüssel des eingeloggten Spielers oder null
function current_user_key() {
  $t = $_COOKIE[SESS_COOKIE] ?? '';
  if (!preg_match('/^[a-f0-9]{48}$/', $t)) return null;
  $s = auth_sessions();
  return isset($s[$t]) ? $s[$t]['user'] : null;
}
// Angezeigter Name des eingeloggten Spielers oder null
function current_user() {
  $k = current_user_key();
  if ($k === null) return null;
  $users = auth_read_json(AUTH_DIR . '/users.json', []);
  return isset($users[$k]) ? ($users[$k]['name'] ?? $k) : null;
}
function auth_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function auth_rate_check() {
  $file = AUTH_DIR . '/rate.json';
  $ip = auth_ip();
  // Sperre VOR dem Lesen: ohne sie zaehlen parallele Loginversuche sich gegenseitig
  // weg (10 gleichzeitige Versuche galten ggf. als einer) -> Rate-Limit umgehbar.
  auth_lock($file);
  $r = auth_read_json($file, []);
  $now = time();
  $e = $r[$ip] ?? ['n' => 0, 'until' => 0];
  if ($e['until'] < $now) $e = ['n' => 0, 'until' => $now + RATE_WINDOW];
  $e['n']++;
  $r[$ip] = $e;
  foreach ($r as $k => $v) if (($v['until'] ?? 0) < $now) unset($r[$k]); // aufräumen
  // Notbremse gegen unbegrenztes Wachsen der Datei (viele IPv6-Quellen im Fenster)
  if (count($r) > 500) { $r = [$ip => $e]; }
  auth_write_json($file, $r);
  if ($e['n'] > RATE_MAX) auth_out(['error' => 'Zu viele Versuche — bitte 15 Minuten warten'], 429);
}
function auth_rate_reset() {
  $file = AUTH_DIR . '/rate.json';
  auth_lock($file);
  $r = auth_read_json($file, []);
  unset($r[auth_ip()]);
  auth_write_json($file, $r);
}

// ---- Routing (nur wenn direkt aufgerufen, nicht als Library) ----
if (!defined('CHESS_AUTH_LIB')) {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $action = $_GET['action'] ?? '';
  $input = [];
  if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw ?: '{}', true);
    $input = is_array($d) ? $d : [];
  }

  if ($method === 'GET' && $action === 'whoami') {
    $k = current_user_key();
    if ($k === null) auth_out(['ok' => true, 'user' => null]);
    $users = auth_read_json(AUTH_DIR . '/users.json', []);
    auth_out(['ok' => true, 'user' => $users[$k]['name'] ?? $k, 'settings' => $users[$k]['settings'] ?? null]);
  }

  // Letzte Auswahl (Stärke/Farbe/Engine/Brett/Figuren/Spielart/Zeit) pro Konto speichern
  if ($method === 'POST' && $action === 'settings') {
    $k = current_user_key();
    if ($k === null) auth_out(['error' => 'Login erforderlich', 'auth' => true], 401);
    $allowed = ['level','side','engine','boardTheme','pieceSet','positionMode','timeMode','incrementMode'];
    $in = is_array($input['settings'] ?? null) ? $input['settings'] : [];
    $clean = [];
    foreach ($allowed as $key) {
      if (isset($in[$key]) && is_scalar($in[$key])) $clean[$key] = mb_substr((string)$in[$key], 0, 20);
    }
    // strict=true: bei beschaedigter users.json abbrechen statt sie mit einem
    // Teil-Datensatz zu ueberschreiben
    auth_lock(AUTH_DIR . '/users.json');
    $users = auth_read_json(AUTH_DIR . '/users.json', [], true);
    if (!isset($users[$k])) auth_out(['error' => 'Unbekannter Nutzer'], 403);
    $users[$k]['settings'] = $clean;
    auth_write_json(AUTH_DIR . '/users.json', $users);
    auth_out(['ok' => true]);
  }

  if ($method === 'POST' && $action === 'register') {
    auth_rate_check();
    $cfg = auth_config();
    if (empty($cfg['invite'])) auth_out(['error' => 'Registrierung derzeit deaktiviert'], 403);
    if (!hash_equals((string)$cfg['invite'], (string)($input['invite'] ?? ''))) auth_out(['error' => 'Einladungscode falsch'], 403);
    $name = trim((string)($input['name'] ?? ''));
    $pass = (string)($input['pass'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{3,20}$/', $name)) auth_out(['error' => 'Name: 3–20 Zeichen, nur Buchstaben/Zahlen'], 400);
    if (strlen($pass) < 6 || strlen($pass) > 200) auth_out(['error' => 'Passwort: mindestens 6 Zeichen'], 400);
    // Sperre + strict: verhindert (a) zwei gleichzeitige Registrierungen, die sich
    // ueberschreiben, und (b) das Ueberschreiben einer beschaedigten users.json —
    // sonst waeren alle bestehenden Konten weg.
    auth_lock(AUTH_DIR . '/users.json');
    $users = auth_read_json(AUTH_DIR . '/users.json', [], true);
    $key = strtolower($name);
    if (isset($users[$key])) auth_out(['error' => 'Name bereits vergeben'], 409);
    $users[$key] = ['name' => $name, 'hash' => password_hash($pass, PASSWORD_DEFAULT), 'created' => time(), 'lastLogin' => time()];
    auth_write_json(AUTH_DIR . '/users.json', $users);
    auth_rate_reset();
    auth_new_session($key);
    auth_out(['ok' => true, 'user' => $name]);
  }

  if ($method === 'POST' && $action === 'login') {
    auth_rate_check();
    $name = trim((string)($input['name'] ?? ''));
    $pass = (string)($input['pass'] ?? '');
    auth_lock(AUTH_DIR . '/users.json');
    $users = auth_read_json(AUTH_DIR . '/users.json', [], true);
    $key = strtolower($name);
    if (!isset($users[$key]) || !password_verify($pass, $users[$key]['hash'] ?? '')) {
      auth_out(['error' => 'Name oder Passwort falsch'], 403);
    }
    $users[$key]['lastLogin'] = time();
    auth_write_json(AUTH_DIR . '/users.json', $users);
    auth_rate_reset();
    auth_new_session($key);
    auth_out(['ok' => true, 'user' => $users[$key]['name'], 'settings' => $users[$key]['settings'] ?? null]);
  }

  if ($method === 'POST' && $action === 'logout') {
    $t = $_COOKIE[SESS_COOKIE] ?? '';
    if (preg_match('/^[a-f0-9]{48}$/', $t)) {
      $file = AUTH_DIR . '/sessions.json';
      auth_lock($file);                       // Sperre vor dem Lesen, sonst kann ein
      $s = auth_read_json($file, []);         // paralleler Login die Session verlieren
      if (isset($s[$t])) { unset($s[$t]); auth_write_json($file, $s); }
      else auth_unlock($file);
    }
    auth_set_cookie('', time() - 3600);
    auth_out(['ok' => true]);
  }

  auth_out(['error' => 'Unknown action'], 400);
}
