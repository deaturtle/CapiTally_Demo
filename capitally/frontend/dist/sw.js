/* CapiTally app shell cache: lets the screens open without a connection after a first online visit.
   The API (/backend/) is never cached here; offline sales are queued by the app itself. */
const CACHE = "capitally-shell-v1";

self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (event) => {
  event.waitUntil((async () => {
    for (const key of await caches.keys()) if (key !== CACHE) await caches.delete(key);
    await self.clients.claim();
  })());
});

self.addEventListener("fetch", (event) => {
  const req = event.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || url.pathname.includes("/backend/")) return;

  event.respondWith((async () => {
    try {
      const res = await fetch(req);
      if (res.ok) (await caches.open(CACHE)).put(req, res.clone());
      return res;
    } catch (err) {
      const hit = await caches.match(req, { ignoreSearch: true });
      if (hit) return hit;
      if (req.mode === "navigate") {
        const shell = (await caches.match(new URL("./", self.location).href)) || (await caches.match(new URL("./index.html", self.location).href));
        if (shell) return shell;
      }
      throw err;
    }
  })());
});
