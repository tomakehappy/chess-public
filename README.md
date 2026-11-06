# Schach ♞

Ein vollständiges Schachspiel als **einzelne HTML-Datei** — gegen den Computer oder online
mit einem Freund. Keine Build-Tools, kein Framework: alles steckt in `chess.html`
(Engine, Brett, Uhren, Sound, Online-Logik). Der Online-Modus nutzt zusätzlich ein
kleines PHP-Backend (`chess_online.php`).

Sprache der Oberfläche: **Deutsch**.

---

## Funktionen

- **Gegen den Computer** mit 4 Stärkestufen (1 sehr leicht … 4 fordernd).
- **Zwei Engines**: eine eingebaute JavaScript-Engine (Negamax + Alpha-Beta + Quiescence)
  und optional **Stockfish** (WASM) für stärkeres Spiel.
- **Online mit Freund**: Einladungslink (optional per WhatsApp), Zugübertragung,
  Remis-Angebot und Revanche.
- **Spielarten**: Standard, Chess960, Antichess (Räuberschach), Zufallsstellung,
  Ohne Damen, Ohne Türme.
- **Uhren**: ohne Uhr (Stoppuhr) oder mit Zeitlimit (1–90 Min) + optionalem Inkrement.
- **Analyse / Tipp**: beste Züge werden auf dem Brett markiert, Bewertungsbalken.
- **Lern-Modi**: Matt-Puzzle (Matt in 2/3), 8-Damen-Problem und **Eröffnungs-Trainer**
  (15 Hauptvarianten üben, der Trainer spielt die Gegenseite).
- **Premove** (Vorausziehen) per Klick **und** per Halten-und-Loslassen (Maus/Touch),
  **Brett drehen**, **Zug zurück**, **Partieverlauf** (SAN), **PGN/FEN-Import**.
- **Spielerkonten** für Online-Partien (Registrierung per Einladungscode, Login,
  Namen der Spieler in der Partie); die **letzte Auswahl** (Stärke, Farbe, Brett,
  Figuren, Spielart, Zeit) wird pro Konto gespeichert.
- **Brett-Themes** (Classic, Holz, Turnier-Grün, Nacht) und **Figurensets**
  (Klassisch, Comic, Symbole, Bronze, Silhouette), Wahl wird gespeichert.
- **Board-Modus** „iPad als Schachbrett": zwei Spieler an einem Gerät, Figuren-/
  Uhrendrehung, Auto-Speichern (nur auf iPad/iPhone sichtbar).
- **Elo lokal** (Übungswert): 5-Min-Partien gegen die eingebaute Engine,
  gespeichert im Browser (localStorage, pro Gerät).
- **Zeitbonus**: bei Partien bis einschließlich 5 Min +1 Sekunde pro Zug
  (gegen Computer clientseitig, online serverseitig).
- **Sound** über die Web Audio API (Sieg-Fanfare, Beethoven bei Niederlage,
  Gegnerzug-Ton, Tab-Titel-Hinweis „Dein Zug").
- **PWA/Offline**: als App installierbar (Service Worker `sw.js`, `manifest.webmanifest`,
  Icons in Gold/Schwarz passend zur Site); gegen den Computer auch offline spielbar.

Vollständige Versionshistorie: siehe [`changelog.html`](changelog.html).

---

## Schnellstart (nur gegen den Computer)

`chess.html` ist eigenständig. Zum Spielen gegen den Computer genügt es, die Datei
in einem modernen Browser zu öffnen.

Empfohlen wird ein lokaler Webserver (damit Web-Worker, Stockfish-WASM und der
Online-Modus sauber laden):

```bash
# im Projektordner
python3 -m http.server 8000
# dann im Browser öffnen:
#   http://localhost:8000/chess.html
```

> Reines Öffnen per `file://` funktioniert für das Spiel gegen den Computer meist
> ebenfalls, kann aber je nach Browser den Web-Worker oder das Laden von
> `stockfish.wasm` einschränken.

---

## Lokal testen (inkl. Online-Modus)

Für den vollständigen Test — also auch Konten und Online-Partien — braucht es PHP.
Dafür liegt ein kleiner Entwicklungsserver bei: `server.js` liefert die statischen
Dateien aus und reicht alle `*.php`-Aufrufe an einen automatisch gestarteten
PHP-Server weiter. Frontend und Backend laufen dadurch unter **einer** Adresse,
genau wie in der Produktion hinter nginx.

**Windows (einmalig einrichten):**

```powershell
powershell -ExecutionPolicy Bypass -File setup_local.ps1
```

Das Skript prüft Node.js, lädt ein portables PHP nach `.local\php` (keine
Admin-Rechte, nichts wird systemweit installiert), schreibt eine passende
`php.ini` und startet anschließend den Server.

**Linux/macOS:**

```bash
./setup_local.sh          # prüft node/php und startet server.js
```

**Danach jederzeit:**

```bash
node server.js --open     # http://localhost:8000/chess.html
```

Optionen: `--port <n>` (Standard 8000), `--php <pfad>` (eigene PHP-Binärdatei),
`--isolate` (COOP/COEP für mehrfädiges Stockfish), `--open` (Browser öffnen).

Der Server verhält sich wie die Produktion: korrekter `application/wasm`-MIME-Type,
`online_games/` und `auth_data/` sind nicht direkt abrufbar, und es wird nichts
gecacht (`Cache-Control: no-store`), damit Änderungen sofort sichtbar sind — auch
mit installiertem Service Worker.

Zum Registrieren wird der Einladungscode gebraucht. Er steht in
`auth_data/config.php` — die Datei liegt bewusst nicht im Repository, sondern wird
auf dem Server angelegt (Vorlage: `auth_data/config.example.php`).

---

## Online-Modus (mit Freund)

Der Online-Modus braucht einen **PHP-fähigen Webserver**. `chess_online.php` legt
laufende Partien als JSON unter `online_games/` ab und wird vom Frontend gepollt.

1. `chess.html`, `chess_online.php`, `stockfish.*` und `changelog.html` auf einen
   Webserver mit PHP legen (z. B. unter `/games/chess/`).
2. Sicherstellen, dass das Verzeichnis `online_games/` vom Webserver beschreibbar
   ist (wird bei Bedarf automatisch angelegt).
3. **Wichtig (Sicherheit):** den direkten Web-Zugriff auf `online_games/` sperren.
   Eine fertige nginx-Konfiguration liegt in
   [`nginx-chess.snippet.conf`](nginx-chess.snippet.conf) bei (inkl. korrektem
   `application/wasm`-MIME-Type für Stockfish).
4. Im Spiel **„Mit Freund"** wählen → der Einladungslink wird erzeugt und kopiert;
   der Gegner öffnet den Link und tritt der Partie bei.

Server-Eckdaten:

- **Spielerkonten**: `create`/`join` erfordern Login (`chess_auth.php`, Sessions als
  Cookie 30 Tage, bcrypt-Hashes, Rate-Limit). Einladungscode in `auth_data/config.php`;
  der Ordner `auth_data/` muss per nginx gesperrt sein (wie `online_games/`).
- Aufräumen: wartende Partien nach **2 h**, beendete nach **6 h**, sonst **24 h**.
- Maximal **100 gleichzeitig laufende** Partien (Schutz gegen Platten-/Inode-Fill).
- **Serverseitige Uhr** (manipulationssicher, Zeitüberschreitung zentral) und
  sparsames Schreiben beim Polling (nur bei echten Änderungen bzw. alle 30 s).
- Zugriff erfolgt ausschließlich serverseitig über `chess_online.php`; Spielstand-
  und Kontodaten sind nicht direkt aus dem Web erreichbar.

---

## Projektstruktur

| Datei | Zweck |
|-------|-------|
| `chess.html` | Komplettes Spiel: UI, eingebaute Engine, Uhren, Sound, Online-Client |
| `chess_online.php` | Backend für den Online-Modus (Partien anlegen, beitreten, Züge, Remis, Revanche, Server-Uhr) |
| `chess_auth.php` | Spielerkonten: Registrieren, Login, Sessions, Einstellungen pro Konto |
| `auth_data/` | Kontodaten (users/sessions/config mit Einladungscode) — per nginx gesperrt |
| `stockfish.js` / `stockfish.wasm` / `stockfish.asm.js` | Stockfish-Engine (WASM mit asm.js-Fallback) |
| `sw.js` / `manifest.webmanifest` / `icons/` / `favicon.ico` | PWA: Offline-Cache, App-Icons (Gold/Schwarz) |
| `server.js` | Lokaler Entwicklungsserver (statische Dateien + PHP-Weiterleitung) |
| `setup_local.ps1` / `setup_local.sh` | Lokale Testumgebung einrichten (portables PHP unter Windows) |
| `nginx-chess.snippet.conf` | nginx-Direktiven für Online-Sicherheit + WASM-MIME-Type |
| `changelog.html` | Versionshistorie |
| `BEKANNTE_FALLEN.md` | **Vor Code-Änderungen lesen**: Fehlermuster mit Ursache und Schaden |
| `MASTER_PROMPT.md` | Aufsetz-Prompt für ein vergleichbares Projekt (Reverse Engineering) |
| `graphify-out/GRAPH_REPORT.md` | Architektur-Landkarte des Codes (Struktur-Analyse, siehe „Weiterentwicklung") |

---

## Browser-Unterstützung

Moderne Browser (Chrome, Firefox, Safari, Edge). Verwendet u. a. Web Workers,
Web Audio, `fetch`, CSS Grid und `dvh`-Einheiten.

---

## Weiterentwicklung

> **Zuerst [`BEKANNTE_FALLEN.md`](BEKANNTE_FALLEN.md) lesen.** Verbindliche Liste der
> Fehlermuster, die in diesem Projekt schon Schaden angerichtet haben — Sperren und
> atomares Schreiben, Generationszähler bei asynchronen Rückrufen, Live- vs.
> Verlaufs-Stellung, `humanColor` im Board-Modus, Prozenthöhen in Flex auf iOS,
> nginx statt Apache, Secrets nie in Client-Dateien. Der daraus abgeleitete
> Aufsetz-Prompt steht in [`MASTER_PROMPT.md`](MASTER_PROMPT.md).

Danach [`graphify-out/GRAPH_REPORT.md`](graphify-out/GRAPH_REPORT.md)
lesen — die Struktur-Analyse (91 Knoten, 124 Kanten, keine Import-Zyklen) zeigt:

- **Kern-Abstraktionen** (meiste Verbindungen): `legalMoves()`, `makeMove()`,
  die Auth-Helfer (`auth_*_json`, `auth_sessions`, `auth_rate_check`) und `chess.html`
  als Einstiegspunkt. Änderungen dort haben die größte Reichweite → besonders sorgfältig testen.
- **Engine-Pipeline**: `legalMoves → negamax → quiesce → evalSide` (+ `bestMove`) —
  hängt zusammen, Varianten-Sonderfälle (Antichess!) stecken in `legalMoves`/`evalSide`.
- **Blob-Worker-Muster**: `engineSrc` wird im Hauptthread evaluiert UND als Worker-Blob
  genutzt — Engine-Änderungen wirken immer auf beide.
- **Online-Architektur**: `chess.html → chess_online.php → online_games/` (nginx-geschützt).
- Der Report wird nach größeren Umbauten neu erzeugt (graphify); Feature-Doku hier in
  der README aktuell halten, sonst erscheinen Features im Graph als isolierte Knoten.

---

## Geplant (To-do)

- **Server-Elo je Spielart** (wie bei Online-Schachseiten): getrennte Ratings pro
  Konto für Bullet/Blitz/Rapid (ggf. je Variante), nur Online-Partien Mensch gegen
  Mensch, serverseitig berechnet (genau einmal pro Partie, manipulationssicher).
- Das lokale 5-Min-Elo gegen den Computer bleibt davon **getrennt** als privater
  Übungswert (localStorage, pro Gerät).

---

## Lizenz / Urheber

© Martin Zoller, 2026.
