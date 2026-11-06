# Master-Prompt: Schachprogramm in einer Sitzung

Reverse Engineering aus dem tatsächlichen Projektverlauf.
Stand: 30.07.2026, Version 26.8 · 3.405 Zeilen, 142 Funktionen, 636 CSS-Regeln.

---

## Vorab: was eine Sitzung realistisch leistet

Ehrliche Einordnung, sonst ist der Prompt irreführend:

| | realistisch in 1 Sitzung | braucht mehrere |
|---|---|---|
| Engine + Regeln + alle Varianten | ja | — |
| Brett-UI, Uhren, Drag&Drop, Themes | ja | — |
| PHP-Backend + Konten + Sperren | ja | — |
| PWA, Sound, Materialbilanz | ja | — |
| **Optik-Feinschliff** (Figuren-Kontur 1px, Materialbilanz-Position, iPhone-Randlosigkeit) | nein | mehrere Runden mit echtem Blick aufs Gerät |
| **Gerätespezifische Bugs** (iOS-Doppelevents, %-Höhen in Flex, Multi-Touch) | nein | nur am echten Gerät auffindbar |
| **Nebenläufigkeits-Bugs** (verlorene Züge, Race Conditions) | teilweise | Lasttest nötig |

Was ein guter Prompt leistet: er verhindert, dass die **Architekturfehler** entstehen, deren Reparatur hier später Sitzungen gekostet hat. Die Chess960-Rochade, das Varianten-Feld, das Sperr-Modell und die Zustandsserialisierung wurden nachträglich eingebaut — das ist der teure Teil. Der Feinschliff bleibt iterativ, egal wie gut der Prompt ist.

Der Prompt unten ist deshalb weniger „Feature-Liste" und mehr **Entscheidungen vorwegnehmen + bekannte Fallen sperren**.

---

## Teil A — Der Prompt (kopierfertig)

> Baue ein vollständiges Schachprogramm. Arbeite in Buckets, und **frage vor dem ersten Code die Interview-Fragen unten ab** — beginne nicht mit Annahmen bei Secrets, Server oder Zielgeräten.
>
> ### Interview zuerst (nicht überspringen)
>
> Stelle mir diese Fragen gebündelt, bevor du irgendetwas schreibst:
>
> **Deployment**
> 1. Zielhost und Pfad? Welcher Webserver — nginx oder Apache? Läuft er in Docker? PHP-Version?
> 2. Wie deploye ich: SCP, git push, manueller Upload? Gibt es ein Skript?
> 3. Terminiert ein Reverse-Proxy das TLS? (Entscheidet, ob `$_SERVER['HTTPS']` gesetzt ist.)
>
> **Secrets** — frage explizit, setze nichts ein
> 4. Registrierung per Einladungscode: welcher Code? Wo darf er stehen?
> 5. Gibt es weitere Zugangscodes (Menü, Elternschutz)? Zeitbasiert oder fix?
> 6. Ist das Repo öffentlich oder privat?
>
> **Nutzung**
> 7. Wie viele Personen, wie viele gleichzeitige Partien realistisch?
> 8. Sprache der Oberfläche? Zeitzone für Zeitangaben?
> 9. Welche Geräte müssen funktionieren — Reihenfolge nach Wichtigkeit? (iPhone-Layout unterscheidet sich stark von PC.)
> 10. Welche Spielarten? Welche Zeitkontrollen?
> 11. Elo: lokal pro Gerät, serverseitig pro Konto, oder beides getrennt?
>
> **Erwartung an dich**
> 12. Soll ich bei größeren Änderungen vorher fragen oder durcharbeiten?
> 13. Wie ausführlich sollen Erklärungen sein?
>
> Fasse meine Antworten als Entscheidungsprotokoll zusammen und lass sie mich bestätigen. Erst dann Code.
>
> ---
>
> ### Architektur — diese Entscheidungen bitte von Anfang an so
>
> Das sind keine Vorschläge, sondern Vorgaben. Sie existieren, weil das Nachrüsten teuer ist.
>
> **A1 Eine einzige HTML-Datei** für das komplette Spiel (Engine, UI, Uhren, Sound, Online-Client). Kein Build, kein Framework. Backend separat als PHP.
>
> **A2 Engine im Doppelbetrieb.** Der Engine-Code steht **einmal** in einem `<script id="engineSrc" type="text/js-worker">`-Block und wird (a) per `eval()` in den Hauptthread geladen, (b) zu einem Blob-Worker konkateniert. Konsequenz, die du dokumentieren musst: jede Engine-Änderung wirkt auf beide Pfade.
>
> **A3 Zustand ist unveränderlich.** `makeMove(state, move)` gibt einen neuen Zustand zurück, mutiert nie. Die Zughistorie speichert Zustandsreferenzen, keine Kopien.
>
> **A4 Variante ist ein erstklassiges Feld im Zustand**, nicht ein globaler UI-Wert. Jede Regelabweichung liest `state.variant`. Lege eine Liste aller Stellen an, die variantenabhängig sind, und halte sie aktuell:
> Zuggenerierung · Legalitätsfilter · Bewertung · Terminalbedingung · Rochade · Umwandlungsoptionen · Schach-/Mattanzeige · Statuszeile · Notation · Engine-Auswahl · Remis-Regeln.
>
> **A5 Rochade generalisiert.** Implementiere Chess960 als den **allgemeinen** Fall und Standardschach als Spezialfall davon — nicht umgekehrt. König- und Turmspalten werden aus der Grundstellung abgeleitet und im Zustand mitgeführt. UCI-Notation für 960: „König schlägt eigenen Turm". FEN: Shredder-Notation, wenn die Geometrie nicht dem Standard entspricht.
> *(Andernfalls wird die 960-Rochade später ein Umbau quer durch Zuggenerierung, FEN, UCI und Engine-Anbindung.)*
>
> **A6 Zustandsserialisierung überträgt nie eine FEN allein.** Aus einer FEN sind Variante und 960-Rochadespalten nicht rekonstruierbar. Speichere Brett, Farbe am Zug, Rochaderechte, König-/Turmspalten und Variante als Struktur. Beim Laden gegen die gewählte Spielart prüfen und Fremdformate ablehnen.
>
> **A7 Uhr serverseitig autoritativ** für Online-Partien. Der Client zeigt an, der Server rechnet. Zeitüberschreitung wird **in jedem** schreibenden Endpunkt geprüft, nicht nur beim Polling — sonst entscheidet ein Rennen, ob die Fahne fällt.
>
> **A8 Genau eine Funktion für Zeitgutschriften**, benutzt von Mensch, Engine und Server. Keine Kopie der Formel an mehreren Stellen.
>
> **A9 Eine Sperre pro Datei um jeden Lesen-Ändern-Schreiben-Zyklus.** `flock` auf eine dedizierte Lock-Datei, dann lesen, ändern, atomar schreiben (`tmp` → `rename`), freigeben.
>
> **A10 Live-Zustand und angesehener Zustand sind getrennt.** Beim Blättern im Partieverlauf darf der laufende Zustand nicht überschrieben werden. Ein eintreffender Engine- oder Gegnerzug stellt zuerst den Live-Zustand her. Die Uhr liest immer die Live-Farbe.
>
> **A11 Generationszähler für alles Asynchrone.** Jede Unterbrechung erhöht ihn: neue Partie, Zug zurück, Import, Rematch, Overlay öffnen. Jeder asynchrone Rückruf (Engine, Analyse, Netzwerk) prüft ihn und verwirft sich sonst. Zusätzlich ein zweiter Riegel: die Engine zieht nie, wenn der Mensch am Zug ist.
>
> **A12 Secrets ausschließlich serverseitig**, in einem per Webserver gesperrten Verzeichnis. Nie in HTML, JS, Changelog oder Repository. Datenverzeichnisse gehören nach Möglichkeit außerhalb des Webroots — die Sperrregel des Webservers ist der zweite Riegel, nicht der erste.
>
> **A13 Perspektive.** Sound, Ergebnisanzeige und Materialbilanz richten sich nach „meiner" Seite. Im Zwei-Spieler-Modus an einem Gerät gibt es kein „meine Seite" — dort ist die Referenz **die Seite am Zug**, nicht eine feste Farbe.
>
> ---
>
> ### Buckets — nach jedem verifizieren und mir berichten
>
> 1. **Regelkern**: Zuggenerierung, Legalität, Rochade (generalisiert), en passant, Umwandlung, Terminalbedingungen, FEN in beide Richtungen, SAN, UCI.
>    *Verifikation:* Grundstellung 20 Züge; Knotenzahlen bis Tiefe 3 gegen bekannte Werte; Rochade kurz/lang in Standard und 960 inkl. Rechteverlust; FEN-Rundreise verlustfrei.
> 2. **Varianten**: alle aus dem Interview. Für jede ein Testfall, der genau die Abweichung prüft.
> 3. **Engine**: Negamax, Alpha-Beta, Quiescence, Stellungsbewertung, Stufen. Blob-Worker.
>    *Verifikation:* findet Matt in 2; bevorzugt in Antichess den Zwangsschlag; Worker und Hauptthread liefern dasselbe.
> 4. **Brett-UI**: Klick **und** Halten-Ziehen-Loslassen über Pointer Events (eine Implementierung für Maus und Touch, mit `pointerId`-Bindung). Uhren, Statuszeile, Zughistorie.
> 5. **Backend**: Partien anlegen/beitreten/ziehen/pollen, Remis, Rematch, Konten mit Passwort-Hashing, Sessions, Rate-Limit, Einladungscode.
>    *Verifikation:* zwei parallele Prozesse hängen je 200 Züge an — es darf kein Zug verloren gehen. Abgeschnittene Datenbank führt zum Abbruch, nicht zum Überschreiben.
> 6. **Feinschliff**: Themes, Figurensets, Sound, Materialbilanz, Premove, PWA, Elo.
> 7. **Abschluss**: Changelog ohne Interna und ohne Codes, README, Upload-Liste, Cache-Version des Service Workers gebumpt.
>
> ---
>
> ### Verboten — bekannte Fallen
>
> Jede Zeile hier hat in einem realen Projekt Zeit gekostet.
>
> **Nebenläufigkeit / Server**
> - `LOCK_EX` beim Schreiben einer Temp-Datei mit eindeutigem Namen. Sperrt nichts.
> - Rückgabewert von `file_put_contents` nur gegen `false` prüfen. Bei voller Platte wird **teilweise** geschrieben und eine Byte-Anzahl zurückgegeben; die abgeschnittene Datei wird dann per `rename` produktiv.
> - Beschädigtes JSON wie „leer" behandeln. Der nächste Schreibvorgang löscht damit alle Daten.
> - Aufräum-Scans über alle Dateien bei jedem Request, wenn im Sekundentakt gepollt wird.
> - Endpunkte ohne Authentifizierung, die über alle Dateien iterieren.
> - Tokens beider Parteien in derselben Antwort ausliefern.
> - Rolle ermitteln und Farbe berechnen, aber nie vergleichen.
> - Schreibende Endpunkte ohne Statusprüfung.
> - Eingaben ohne `(string)`-Cast in `preg_replace` geben bei Array-Eingabe ein Array zurück → Fatal Error.
> - Nur Elementanzahl eines Arrays prüfen, nicht den Inhalt.
> - `.htaccess` auf nginx. Wirkungslos.
>
> **Asynchrones**
> - Timeout-Rückfall, der weder ein `done`-Flag setzt noch den Worker beendet → zwei Züge.
> - Rückrufe ohne Generationsprüfung.
> - `thinking` als Sperre benutzen, obwohl es erst *nach* der Verzögerung gesetzt wird.
>
> **Mobil / iOS**
> - Prozenthöhen für Kinder gestreckter Flex-Container. Auf iOS kollabieren sie. Aus der gerenderten Pixelgröße rechnen.
> - Drag ohne `pointerId`-Bindung. Der zweite Finger beendet ihn.
> - Globaler `keydown` **und** `input`-Handler auf demselben Feld. Zählt auf iOS doppelt.
> - Layout-Annahmen ohne echten Blick aufs Gerät.
>
> **Struktur**
> - Zug-Anwendung mehrfach kopieren (eigener Zug, Engine, Netzwerk). Läuft auseinander — hier war es die Zeitgutschrift, die nur der Mensch bekam.
> - Eine feste Spielerfarbe als Ersatz für „Seite am Zug" im Zwei-Spieler-Modus.
> - Gewinner aus dem Anzeigetext per Textsuche ermitteln.
> - Zustandsvariablen bei „neue Partie" einzeln zurücksetzen. Eine Funktion, die *alle* zurücksetzt, und die überall benutzen.
>
> ---
>
> ### Arbeitsweise
>
> - Vor jedem Bucket: kurz sagen, was kommt. Danach: gegen die genannten Kriterien prüfen und Ergebnis berichten.
> - Riskante Aktionen (Löschen, Überschreiben, Deploy, Secrets rotieren) vorher explizit bestätigen lassen.
> - Verifikation heißt ausführen, nicht lesen. Syntaxprüfung, Regeltests mit Zahlen, Nebenläufigkeitstest mit echten Prozessen.
> - Findest du einen Fehler in deiner eigenen früheren Arbeit: sagen, nicht stillschweigend korrigieren.
> - Am Ende: Liste welche Datei wohin, plus alles, was ich manuell tun muss.

---

## Teil B — Warum diese Punkte drinstehen

Zuordnung zu den tatsächlich aufgetretenen Problemen:

| Prompt-Punkt | Was ohne ihn passiert ist |
|---|---|
| A5 Rochade generalisiert | Chess960-Rochade war zunächst deaktiviert, das Nachrüsten betraf Zuggenerierung, FEN, UCI und Stockfish-Anbindung gleichzeitig |
| A4 Variante im Zustand | Antichess quer durch 15 Stellen nachgezogen; drei fielen trotzdem durchs Raster |
| A6 Serialisierung ohne FEN | Gespeicherte Antichess-Partie wurde nach Neuladen normales Schach; 960-Partien nie ladbar (0 von 8) |
| A7 Uhr in jedem Endpunkt | Abgelaufene Zeit ließ sich durch schnelles Ziehen zurückholen |
| A8 eine Gutschrift-Funktion | Bonus existierte dreifach, die Engine bekam ihn nicht — systematischer Uhrvorteil in genau den gewerteten Partien |
| A9 Sperre pro Datei | Im Lasttest gingen ohne Sperre 196 von 400 Zügen verloren |
| A10 Live-Zustand getrennt | Blättern im Verlauf + eintreffender Zug ⇒ Zug auf alter Stellung, Historie verzweigt, Uhr für falsche Farbe |
| A11 Generationszähler | „Zug zurück" während der Bedenkzeit ⇒ Computer zog für den Menschen, Partie hing |
| A13 Perspektive / Seite am Zug | Im Zwei-Spieler-Modus sah Schwarz den Schlagzwang nie und bekam weiße Umwandlungsfiguren |
| Verbot `.htaccess` | Datei angelegt, wirkungslos, musste ersetzt werden |
| Verbot Prozenthöhen | Geschlagene Figuren am iPhone unsichtbar |
| Verbot Timeout ohne `done` | Engine machte zwei Züge, Uhren und Historie inkonsistent |
| A12 Secrets serverseitig | Einladungscode lag im Git und war gepusht |

---

## Teil C — Was der Prompt nicht abkürzt

Drei Dinge bleiben iterativ, unabhängig von der Promptqualität:

**Optik.** Formulierungen wie „Materialbilanz neben dem Brett" reichen nicht. Hier brauchte es vier Runden, bis Position, Größe, Wachstumsrichtung und Drehverhalten stimmten. Ein Prompt kann das nur vorbereiten, indem er nach Referenzbildern fragt.

**Gerätespezifisches.** Die iOS-Bugs waren erst am Gerät sichtbar. Der Prompt kann die bekannten Klassen sperren, aber nicht die unbekannten.

**Was du eigentlich willst.** Mehrere gute Features entstanden erst aus „was fehlt noch?" — Schnellrevanche, Materialbilanz, Schach-Warnung. Das ist kein Promptversagen, sondern normale Produktentwicklung.

**Praktische Empfehlung:** den Prompt für Bucket 1–5 verwenden (Regelkern bis Backend — dort zahlt sich Präzision voll aus), Bucket 6–7 bewusst iterativ mit Blick aufs Gerät. Und: die Verbotsliste als Datei ins Projekt legen, damit sie in jeder Folgesitzung mitgelesen wird.
