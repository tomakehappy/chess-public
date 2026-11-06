<?php
// Vorlage. Als auth_data/config.php auf dem Server anlegen und den Code aendern.
// null = Registrierung deaktiviert.
// Das Verzeichnis auth_data/ MUSS per Webserver gesperrt sein (deny all),
// siehe nginx-chess.snippet.conf — es enthaelt Passwort-Hashes und Sessions.
return [
  'invite' => null,
];
