#!/usr/bin/env node
/**
 * Lokaler Entwicklungsserver für das Schachspiel.
 *
 *   node server.js [--port 8000] [--php C:\pfad\zu\php.exe] [--isolate] [--open]
 *
 * Liefert die statischen Dateien (chess.html, stockfish.wasm, icons, ...) aus und
 * reicht alle *.php-Aufrufe an einen automatisch gestarteten PHP-Built-in-Server
 * weiter. Damit laufen Frontend und Online-Backend unter derselben Origin, genau
 * wie in der Produktion hinter nginx.
 */

'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const { spawn, spawnSync } = require('child_process');

const ROOT = __dirname;
const IS_WINDOWS = process.platform === 'win32';

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.htm': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.webmanifest': 'application/manifest+json; charset=utf-8',
  '.wasm': 'application/wasm',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.txt': 'text/plain; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
};

// Verzeichnisse/Dateien, die im Web nicht erreichbar sein dürfen (wie in nginx-chess.snippet.conf).
const DENY = [/^online_games(\/|$)/i, /^auth_data(\/|$)/i, /^\.git(\/|$)/i, /^\.local(\/|$)/i, /^node_modules(\/|$)/i, /^graphify-out(\/|$)/i, /\.ps1$/i, /\.md$/i, /\.bak$/i];

function parseArgs(argv) {
  const opts = { port: Number(process.env.PORT) || 8000, php: process.env.PHP_BIN || '', isolate: false, open: false };
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--port' || a === '-p') opts.port = Number(argv[++i]);
    else if (a === '--php') opts.php = argv[++i];
    else if (a === '--isolate') opts.isolate = true;
    else if (a === '--open') opts.open = true;
    else if (a === '--help' || a === '-h') opts.help = true;
    else { console.error('Unbekannte Option: ' + a); opts.help = true; }
  }
  return opts;
}

function usage() {
  console.log([
    'Aufruf: node server.js [Optionen]',
    '',
    '  --port <n>    Port des lokalen Servers (Standard 8000)',
    '  --php <pfad>  Pfad zur PHP-Binärdatei (sonst .local/php bzw. PATH)',
    '  --isolate     Cross-Origin-Isolation (COOP/COEP) für mehrfädiges Stockfish',
    '  --open        Startseite anschließend im Browser öffnen',
  ].join('\n'));
}

function findPhp(explicit) {
  const candidates = [];
  if (explicit) candidates.push(explicit);
  candidates.push(path.join(ROOT, '.local', 'php', IS_WINDOWS ? 'php.exe' : 'php'));
  candidates.push(IS_WINDOWS ? 'php.exe' : 'php');
  for (const bin of candidates) {
    if (bin.includes(path.sep) && !fs.existsSync(bin)) continue;
    const probe = spawnSync(bin, ['-v'], { stdio: 'ignore' });
    if (!probe.error && probe.status === 0) return bin;
  }
  return null;
}

function findFreePort(start, limit = start + 50) {
  return new Promise((resolve, reject) => {
    const srv = http.createServer();
    // vorher "start < start + 50" -> immer wahr, Suche ohne Obergrenze
    srv.on('error', () => (start < limit ? findFreePort(start + 1, limit).then(resolve, reject) : reject(new Error('kein freier Port'))));
    srv.listen(start, '127.0.0.1', () => {
      const port = srv.address().port;
      srv.close(() => resolve(port));
    });
  });
}

function waitForPhp(port, child, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  return new Promise((resolve, reject) => {
    const tick = () => {
      if (child.exitCode !== null) return reject(new Error('PHP-Server wurde sofort beendet'));
      const req = http.request({ host: '127.0.0.1', port, path: '/', method: 'HEAD' }, (res) => { res.resume(); resolve(); });
      req.on('error', () => (Date.now() > deadline ? reject(new Error('PHP-Server antwortet nicht')) : setTimeout(tick, 150)));
      req.end();
    };
    tick();
  });
}

function isDenied(rel) {
  return DENY.some((re) => re.test(rel));
}

function safeRelPath(urlPath) {
  let decoded;
  try { decoded = decodeURIComponent(urlPath); } catch { return null; }
  const rel = path.normalize(decoded).replace(/^([/\\])+/, '');
  if (rel.split(/[/\\]/).includes('..')) return null;
  return rel;
}

function applyCommonHeaders(res, opts) {
  // Entwicklung: nie cachen, damit Änderungen sofort sichtbar sind (auch mit Service Worker).
  res.setHeader('Cache-Control', 'no-store');
  if (opts.isolate) {
    res.setHeader('Cross-Origin-Opener-Policy', 'same-origin');
    res.setHeader('Cross-Origin-Embedder-Policy', 'require-corp');
    res.setHeader('Cross-Origin-Resource-Policy', 'same-origin');
  }
}

function sendError(res, code, msg) {
  res.writeHead(code, { 'Content-Type': 'text/plain; charset=utf-8' });
  res.end(msg + '\n');
}

function serveStatic(req, res, rel, opts) {
  let file = path.join(ROOT, rel === '' ? 'index.html' : rel);
  let stat;
  try { stat = fs.statSync(file); } catch { return sendError(res, 404, '404 – nicht gefunden: /' + rel); }
  if (stat.isDirectory()) {
    file = path.join(file, 'index.html');
    try { stat = fs.statSync(file); } catch { return sendError(res, 404, '404 – kein index.html in /' + rel); }
  }
  applyCommonHeaders(res, opts);
  res.setHeader('Content-Type', MIME[path.extname(file).toLowerCase()] || 'application/octet-stream');
  res.setHeader('Content-Length', stat.size);
  if (req.method === 'HEAD') return res.end();
  fs.createReadStream(file).on('error', () => sendError(res, 500, '500 – Lesefehler')).pipe(res);
}

function proxyToPhp(req, res, phpPort, opts) {
  const headers = Object.assign({}, req.headers);
  delete headers['accept-encoding'];
  headers.host = '127.0.0.1:' + phpPort;
  const proxied = http.request({ host: '127.0.0.1', port: phpPort, path: req.url, method: req.method, headers }, (up) => {
    applyCommonHeaders(res, opts);
    const skip = new Set(['host', 'connection', 'keep-alive', 'transfer-encoding', 'x-powered-by', 'cache-control']);
    for (const [k, v] of Object.entries(up.headers)) {
      if (!skip.has(k)) res.setHeader(k, v);
    }
    res.writeHead(up.statusCode || 502);
    up.pipe(res);
  });
  proxied.on('error', (err) => sendError(res, 502, '502 – PHP-Backend nicht erreichbar: ' + err.message));
  req.pipe(proxied);
}

async function main() {
  const opts = parseArgs(process.argv);
  if (opts.help) return usage();

  const php = findPhp(opts.php);
  if (!php) {
    console.error([
      'PHP wurde nicht gefunden.',
      IS_WINDOWS
        ? 'Einmalig einrichten:  powershell -ExecutionPolicy Bypass -File setup_local.ps1'
        : 'Installieren, z. B.:  sudo apt-get install -y php-cli   (oder: brew install php)',
      'Alternativ: node server.js --php <pfad-zu-php>',
    ].join('\n'));
    process.exit(1);
  }

  fs.mkdirSync(path.join(ROOT, 'online_games'), { recursive: true });

  const phpPort = await findFreePort(8899);
  const child = spawn(php, ['-S', '127.0.0.1:' + phpPort, '-t', ROOT], { cwd: ROOT, stdio: ['ignore', 'inherit', 'inherit'] });
  child.on('exit', (code) => {
    if (code !== 0 && code !== null) console.error('PHP-Server beendet (Code ' + code + ')');
  });

  const shutdown = (code = 0) => { try { child.kill(); } catch {} process.exit(code); };
  process.on('SIGINT', () => shutdown(0));
  process.on('SIGTERM', () => shutdown(0));
  process.on('exit', () => { try { child.kill(); } catch {} });

  await waitForPhp(phpPort, child, 10000);

  const server = http.createServer((req, res) => {
    const urlPath = (req.url || '/').split('?')[0];
    const rel = safeRelPath(urlPath);
    if (rel === null) return sendError(res, 400, '400 – ungültiger Pfad');
    if (isDenied(rel)) return sendError(res, 404, '404 – gesperrt');
    if (/\.php$/i.test(rel)) return proxyToPhp(req, res, phpPort, opts);
    if (req.method !== 'GET' && req.method !== 'HEAD') return sendError(res, 405, '405 – Methode nicht erlaubt');
    serveStatic(req, res, rel, opts);
  });

  server.listen(opts.port, '127.0.0.1', () => {   // nur lokal, nicht im ganzen LAN
    const url = 'http://localhost:' + opts.port + '/chess.html';
    console.log('Schach lokal:   ' + url);
    console.log('PHP-Backend:    ' + php + '  (intern auf 127.0.0.1:' + phpPort + ')');
    if (opts.isolate) console.log('Cross-Origin-Isolation aktiv (COOP/COEP)');
    console.log('Beenden mit Strg+C');
    if (opts.open) {
      const cmd = IS_WINDOWS ? 'cmd' : process.platform === 'darwin' ? 'open' : 'xdg-open';
      const args = IS_WINDOWS ? ['/c', 'start', '', url] : [url];
      spawn(cmd, args, { stdio: 'ignore', detached: true }).unref();
    }
  });
  server.on('error', (err) => {
    console.error(err.code === 'EADDRINUSE' ? 'Port ' + opts.port + ' ist belegt – mit --port <n> einen anderen wählen.' : String(err));
    shutdown(1);
  });
}

main().catch((err) => { console.error(String(err.message || err)); process.exit(1); });
