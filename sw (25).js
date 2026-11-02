/* =============================================================================
 * VAT · Service Worker (PWA offline)
 * Estrategia "red primero, caché de respaldo": con conexión siempre se sirve la
 * versión más reciente (y se guarda copia); sin conexión se usa la copia.
 * Si se usara "caché primero", los usuarios quedarían con una versión vieja
 * aunque se publique una actualización. Si este archivo no existiera, la app
 * instalada en el celular no abriría sin internet.
 * Las llamadas a /api/ nunca se cachean: cada análisis debe calcularse de nuevo.
 * ============================================================================= */
const CACHE = 'vat-v3'; // Cambiar el nombre al publicar una versión fuerza a limpiar cachés antiguas.
const ARCHIVOS = ['./', 'index.html', 'assets/css/estilos.css', 'assets/js/app.js', 'assets/js/motor-vat.js',
  'modelo/vat-modelo.js', 'assets/img/icono.svg', 'manifest.webmanifest'];

/* install: precarga los archivos para que el primer uso offline ya funcione. */
self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(ARCHIVOS); }));
  self.skipWaiting(); // Activa la nueva versión sin esperar a cerrar todas las pestañas.
});

/* activate: borra cachés de versiones anteriores (si no, ocuparían espacio y podrían servirse). */
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) {
    return Promise.all(ks.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

/* fetch: red primero; si falla (sin conexión), copia en caché. */
self.addEventListener('fetch', function (e) {
  if (e.request.method !== 'GET' || e.request.url.indexOf('/api/') !== -1) return; // API y POST: siempre a la red.
  e.respondWith(
    fetch(e.request).then(function (r) {
      if (r && r.ok && new URL(e.request.url).origin === self.location.origin) {
        const copia = r.clone(); // La respuesta sólo puede leerse una vez: se clona para guardarla.
        caches.open(CACHE).then(function (c) { c.put(e.request, copia); });
      }
      return r;
    }).catch(function () { return caches.match(e.request); })
  );
});
