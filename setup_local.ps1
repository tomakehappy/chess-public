# =====================================================================
# Lokale Testumgebung einrichten (Windows).
#
#   powershell -ExecutionPolicy Bypass -File setup_local.ps1
#
# Lädt ein portables PHP nach .local\php (nichts wird systemweit installiert,
# keine Admin-Rechte nötig), aktiviert die benötigten Extensions und startet
# anschließend auf Wunsch den lokalen Server (server.js).
# =====================================================================

[CmdletBinding()]
param(
  [string]$PhpVersion = '8.3',   # gewünschte PHP-Hauptversion
  [switch]$Force,                # vorhandene .local\php-Installation ersetzen
  [switch]$NoStart               # nur einrichten, Server nicht starten
)

$ErrorActionPreference = 'Stop'
$root    = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpDir  = Join-Path $root '.local\php'
$phpExe  = Join-Path $phpDir 'php.exe'

function Write-Step($msg) { Write-Host "==> $msg" -ForegroundColor Cyan }

# --- Node.js prüfen ---------------------------------------------------
$node = Get-Command node -ErrorAction SilentlyContinue
if (-not $node) {
  Write-Host 'Node.js fehlt. Bitte einmalig installieren: https://nodejs.org/ (LTS)' -ForegroundColor Yellow
  Write-Host 'Danach dieses Skript erneut ausfuehren.' -ForegroundColor Yellow
  exit 1
}
Write-Step "Node.js gefunden: $(node -v)"

# --- Vorhandenes PHP im PATH akzeptieren ------------------------------
if (-not $Force -and -not (Test-Path $phpExe)) {
  $sysPhp = Get-Command php.exe -ErrorAction SilentlyContinue
  if ($sysPhp) {
    Write-Step "PHP bereits im PATH: $($sysPhp.Source)"
    $phpExe = $sysPhp.Source
  }
}

# --- Portables PHP laden ----------------------------------------------
if ($Force -or -not (Test-Path $phpExe)) {
  Write-Step "Lade portables PHP $PhpVersion (x64, non-thread-safe) ..."
  $listUrl = 'https://windows.php.net/downloads/releases/releases.json'
  $releases = Invoke-RestMethod -Uri $listUrl -UseBasicParsing
  $rel = $releases.$PhpVersion
  if (-not $rel) { throw "PHP $PhpVersion ist auf windows.php.net nicht (mehr) verfuegbar." }

  $build = $rel.PSObject.Properties |
           Where-Object { $_.Value.zip -and $_.Name -match 'nts-.*-x64' } |
           Select-Object -First 1
  if (-not $build) { throw 'Kein passendes x64-NTS-Paket gefunden.' }

  $zipName = $build.Value.zip.path
  $zipUrl  = "https://windows.php.net/downloads/releases/$zipName"
  $tmpZip  = Join-Path $env:TEMP $zipName

  Invoke-WebRequest -Uri $zipUrl -OutFile $tmpZip -UseBasicParsing
  if (Test-Path $phpDir) { Remove-Item $phpDir -Recurse -Force }
  New-Item -ItemType Directory -Path $phpDir -Force | Out-Null
  Expand-Archive -Path $tmpZip -DestinationPath $phpDir -Force
  Remove-Item $tmpZip -Force
  $phpExe = Join-Path $phpDir 'php.exe'
  Write-Step "PHP entpackt nach $phpDir"
}

# --- php.ini fuer die Online-Funktionen ------------------------------
$iniPath = Join-Path (Split-Path -Parent $phpExe) 'php.ini'
if ((Split-Path -Parent $phpExe) -eq $phpDir -and -not (Test-Path $iniPath)) {
  $devIni = Join-Path $phpDir 'php.ini-development'
  if (Test-Path $devIni) { Copy-Item $devIni $iniPath }
  $ini = if (Test-Path $iniPath) { Get-Content $iniPath -Raw } else { '' }
  $ini += @"

; --- lokale Testumgebung (setup_local.ps1) ---
extension_dir = "ext"
extension=mbstring
extension=openssl
extension=fileinfo
date.timezone = Europe/Vienna
display_errors = On
error_reporting = E_ALL
"@
  Set-Content -Path $iniPath -Value $ini -Encoding UTF8
  Write-Step "php.ini geschrieben: $iniPath"
}

& $phpExe -v | Select-Object -First 1 | ForEach-Object { Write-Step $_ }
& $phpExe -l (Join-Path $root 'chess_online.php')
& $phpExe -l (Join-Path $root 'chess_auth.php')

# --- Datenverzeichnisse -----------------------------------------------
New-Item -ItemType Directory -Path (Join-Path $root 'online_games') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $root 'auth_data') -Force | Out-Null

Write-Host ''
Write-Step 'Fertig. Start jederzeit mit:  node server.js --open'

if (-not $NoStart) {
  Push-Location $root
  try { & node 'server.js' '--php' $phpExe '--open' } finally { Pop-Location }
}
