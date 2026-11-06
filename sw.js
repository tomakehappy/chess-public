/* Service Worker für Schach (PWA / Offline gegen den Computer) */
var CACHE = 'chess-v26.10.30.03';   // = App-Version (= Programmierdatum yy.mm.dd.NN; NN je Aenderung hochzaehlen, taeglich neu ab 01); bei jeder Aenderung an chess.html/Assets neu setzen!
// stockfish.asm.js fehlt absichtlich: wird nirgends geladen (nur stockfish.js + .wasm),
// sparte ~1 MB Vorab-Download pro Installation.
var ASSETS = [
  './chess.html',
  './changelog.html',
  './manifest.webmanifest',
  './favicon.ico',
  './icons/icon-192.png',
  './icons/icon-512.png',
  './icons/icon-512-maskable.png',
  './icons/apple-touch-icon.png',
  './stockfish.js',
  './stockfish.wasm'
];

self.addEventListener('install', function(e){
  e.waitUntil(
    caches.open(CACHE).then(function(c){
      // cache:'reload' -> am Server holen, nicht aus dem (evtl. alten) HTTP-Cache
      return c.addAll(ASSETS.map(function(u){ return new Request(u, {cache: 'reload'}); }));
    }).then(function(){ return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function(e){
  e.waitUntil(
    caches.keys().then(function(keys){
      return Promise.all(keys.map(function(k){ if(k!==CACHE) return caches.delete(k); }));
    }).then(function(){ return self.clients.claim(); })
  );
});

function offline(){ return new Response('Offline', {status: 503, headers: {'Content-Type': 'text/plain; charset=utf-8'}}); }

self.addEventListener('fetch', function(e){
  var req = e.request;
  if(req.method !== 'GET') return;
  var url = new URL(req.url);
  if(url.origin !== self.location.origin) return;
  // Online-Backend und Konten nie cachen — immer Netzwerk (aktuelle Daten/Login-Status)
  if(url.pathname.indexOf('chess_online.php') !== -1 || url.pathname.indexOf('chess_auth.php') !== -1){
    return; // Browser-Standardverhalten (Netzwerk)
  }
  // Seiten (chess.html, changelog.html, Navigationen): Netz zuerst -> neue Version schon beim
  // ERSTEN Laden (vorher cache-first: erst beim zweiten). Gespeichert wird immer ohne Query,
  // damit Einladungs-URLs (?game=…&token=…) nicht als eigene Einträge samt Token im Cache landen.
  var isPage = req.mode === 'navigate' || /\.html$/.test(url.pathname);
  if(isPage){
    var key = url.origin + url.pathname;
    e.respondWith(
      fetch(req).then(function(res){
        if(res && res.status === 200 && res.type === 'basic'){
          var copy = res.clone();
          caches.open(CACHE).then(function(c){ c.put(key, copy); });
        }
        return res;
      }).catch(function(){
        return caches.match(key).then(function(hit){
          return hit || caches.match('./chess.html').then(function(shell){ return shell || offline(); });
        });
      })
    );
    return;
  }
  // Übrige Assets: cache-first, im Hintergrund auffrischen (nur ohne Query cachen)
  e.respondWith(
    caches.match(req).then(function(cached){
      var network = fetch(req).then(function(res){
        if(!url.search && res && res.status === 200 && res.type === 'basic'){
          var copy = res.clone();
          caches.open(CACHE).then(function(c){ c.put(req, copy); });
        }
        return res;
      }).catch(function(){ return cached || offline(); });
      return cached || network;
    })
  );
});
