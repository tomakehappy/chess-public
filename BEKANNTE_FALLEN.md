# Bekannte Fallen — vor Code-Änderungen lesen

Jeder Punkt hier ist in diesem Projekt real aufgetreten und hat Zeit gekostet.
Die Liste ist **verbindlich**: diese Muster nicht neu einbauen, und bei Änderungen
in der Nähe prüfen, ob die Absicherung noch greift.

Stand: 30.07.2026 · Version 26.8

---

## Nebenläufigkeit & Server (PHP)

| ✗ Falsch | ✓ Richtig | Schaden |
|---|---|---|
| `LOCK_EX` beim Schreiben einer Temp-Datei mit `uniqid()`-Namen | `flock` auf eine dedizierte `.lock`-Datei **vor** dem Lesen | Sperrt nichts. Im Lasttest gingen **196 von 400 Zügen** verloren (Poll vs. move) |
| Rückgabe von `file_put_contents` nur gegen `false` prüfen | geschriebene Byte-Anzahl mit `strlen($json)` vergleichen | Bei voller Platte wird *teilweise* geschrieben; `rename` macht die abgeschnittene Datei produktiv |
| Beschädigtes JSON wie „leer" behandeln | „Datei fehlt" von „Datei unlesbar" trennen; im Schreibpfad abbrechen | Nächste Registrierung überschreibt `users.json` → **alle Konten weg** |
| Aufräum-Scan über alle Dateien bei jedem Request | nur bei `create` bzw. probabilistisch; Zähler cachen | Bei Polling im Sekundentakt ~200 Datei-Reads pro Request → DoS-Verstärker |
| Endpunkt ohne Auth, der über alle Dateien iteriert | cachen (TTL) und/oder Login verlangen | Billigster Weg, den VPS lahmzulegen |
| Rolle ermitteln, Farbe berechnen, nie vergleichen | `$game['players'][$role]['color'] === $movingColor` prüfen | Jede Seite konnte die Züge der anderen einreichen |
| Zeitprüfung nur im Poll | in **jedem** schreibenden Endpunkt | Abgelaufene Zeit ließ sich durch schnelles Ziehen zurückholen |
| Schreibender Endpunkt ohne Statusprüfung | `status === 'playing'` erzwingen | Züge landeten in wartenden und beendeten Partien |
| Tokens beider Parteien in derselben Antwort | eigene Tokens strippen | Wer den Einladungslink hat, kann beide Seiten spielen |
| Eingabe ohne `(string)`-Cast in `preg_replace` | immer casten | `{"token":[]}` → Array → `TypeError` → HTTP 500 + Log-Flut |
| Nur `count()` eines Arrays prüfen | Inhalt gegen Whitelist prüfen | 1-MB-String pro Feld akzeptiert → Partiedatei bis 8 MB × 100 |
| `.htaccess` | nginx-`location`-Block in der Site-Config | **Produktion läuft nginx in Docker.** `.htaccess` ist wirkungslos |
| Secrets in Client-Dateien oder im Repo | nur serverseitig, Verzeichnis in `.gitignore` **und** per nginx gesperrt | Einladungscode lag im Git und war gepusht |

---

## Asynchrones (JS)

| ✗ Falsch | ✓ Richtig | Schaden |
|---|---|---|
| Timeout-Rückfall ohne `done=true` und ohne `terminate()` | beides setzen | Engine machte **zwei Züge**, Mensch übersprungen, Uhren/Historie inkonsistent |
| Asynchroner Rückruf ohne Generationsprüfung | `gameGen` beim Start merken, im Rückruf vergleichen | Ergebnisse alter Partien fielen in die neue |
| `thinking` als Sperre benutzen | `gameGen++` an jeder Unterbrechung | `thinking` wird erst *nach* der 1–3-s-Verzögerung gesetzt → „Zug zurück" ließ den Computer für den Menschen ziehen, Partie hing |
| Analyse-Rückruf prüft nur `analyzeMode` | eigener `analysisGen`-Zähler | Badges der *vorigen* Stellung wurden eingezeichnet |

**`gameGen` muss hoch bei:** neue Partie, Zeitauswahl-Overlay, Zug zurück, Import, Rematch, Online-Join.

---

## Zustandsführung

| ✗ Falsch | ✓ Richtig | Schaden |
|---|---|---|
| Globalen Live-Zustand beim Blättern im Verlauf überschreiben | `liveState` sichern, `enterHistory()`/`leaveHistory()` | Eintreffender Zug landete auf der alten Stellung, Historie verzweigte, Uhr lief für die falsche Farbe |
| Zug im Verlaufs-Modus blind ans Ende von `gameHist` hängen | Absichtliches Verzweigen NUR über `branchHere()`: kürzt `gameHist`/`sanList` auf `viewPly+1`, macht die angesehene Stellung zur Live-Stellung, `gameGen++`, Uhr aus `gameHist[viewPly+1].clock`. **Online verboten** (gemeinsamer Spielstand), gegen Computer nur bei eigenem Zug. Das Feature „ab hier weiterspielen" NICHT als Bug wieder wegblocken. | Ohne `branchHere` verzweigte die Historie unkontrolliert; mit Blind-Block war „weiterspielen" gar nicht möglich |
| Uhr liest `state.turn` | `liveTurn()` | Beim Blättern verbrauchte man die Zeit des Gegners |
| Zustand als FEN serialisieren | Struktur mit Brett, Rochaderechten, König-/Turmspalten **und Variante** | Gespeicherte Antichess-Partie wurde normales Schach; 960-Partien nie ladbar (0 von 8) |
| Zug-Anwendung mehrfach kopieren | eine Funktion, überall benutzt | Zeitgutschrift existierte dreifach — die Engine bekam sie nicht, systematischer Uhrvorteil in genau den gewerteten Partien |
| Feste Spielerfarbe als Ersatz für „Seite am Zug" | im Zwei-Spieler-Modus `state.turn` | `humanColor` ist dort fix `'w'` → Schwarz sah den Schlagzwang nie und bekam weiße Umwandlungsfiguren |
| Gewinner per Textsuche im Anzeigetext | Farbe explizit übergeben | Bricht, sobald eine Meldung beide Farben enthält |
| Zustandsvariablen einzeln zurücksetzen | eine Reset-Funktion, überall benutzt | Overlay blieb über dem Brett liegen und schluckte alle Klicks |
| Uhr auf 0 setzen beim Zurücksetzen | auf `clockLimit` setzen | Partie endete nach 250 ms mit „Zeit abgelaufen" |
| Dropdown-Handler setzt nur `flipped` | auch `humanColor`, solange die Partie nicht läuft | Farbe „Schwarz" wurde ignoriert, eigene Figuren reagierten nicht |

---

## Mobil / iOS

| ✗ Falsch | ✓ Richtig | Schaden |
|---|---|---|
| Prozenthöhen für Kinder gestreckter Flex-Container | aus gerenderter Pixelgröße rechnen, bei Resize/Drehung neu | Auf iOS kollabiert → geschlagene Figuren unsichtbar |
| Drag ohne `pointerId`-Bindung | `mouseDrag.id=e.pointerId`, in move/up vergleichen | Zweiter Finger legte die Figur an falscher Stelle ab |
| Globaler `keydown` **und** `input`-Handler auf demselben Feld | einen der beiden früh verlassen | Zählt auf iOS doppelt → Code-Eingabe konnte nie stimmen |
| Layout-Annahmen ohne Blick aufs Gerät | Screenshot anfordern | Mehrere Korrekturrunden |

---

## Prozess

- **Verifikation heißt ausführen, nicht lesen.** Syntaxprüfung aller `<script>`-Blöcke, Regeltests mit konkreten Zahlen (Grundstellung = 20 Züge), Nebenläufigkeit mit echten Prozessen testen.
- **Engine-Änderungen wirken doppelt.** `engineSrc` wird per `eval()` im Hauptthread geladen **und** zum Blob-Worker konkateniert.
- **PHP-CLI ist in der Sandbox nicht verfügbar.** Ersatz: Klammerbalance nach Strip von Strings/Kommentaren, Prüfung auf doppelte/unbekannte Funktionen, Logik in Node nachbauen und testen.
- **Versionsnummer = `yy.mm.dd.NN`** (Wien-Zeit): Datum + laufende Unternummer `NN`, bei JEDER Änderung +1 (täglich neu ab `01`), z. B. `26.09.06.01`. An DREI Stellen gleich setzen: Footer in `chess.html`, `changelog.html` (Header + Abschnittsüberschrift), und `sw.js`-Cache-Name (`chess-v<version>`). **Bei jeder Änderung Version hochziehen UND Changelog-Eintrag ergänzen.** Datum per `TZ=Europe/Vienna date +"%y.%m.%d"` holen, nicht raten.
- **`sw.js`-Cache-Version bei jeder Asset-Änderung neu setzen** (= aktuelles Datum), sonst sieht man nach dem Upload die alte Version.
- **Changelog:** keine Codes, keine Dateinamen, keine Interna. Nach jeder Änderung Tag-Balance prüfen.
- **Eigene Fehler benennen**, nicht stillschweigend korrigieren.

---

Ausführlicher Kontext und der daraus abgeleitete Aufsetz-Prompt: [`MASTER_PROMPT.md`](MASTER_PROMPT.md)
