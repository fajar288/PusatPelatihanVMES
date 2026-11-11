/*
 * Service worker PPTI VMES (PWA, Phase 5).
 *
 * Strategi:
 * - Aset build Next (/_next/static/*, ikon, font): cache-first (nama file ber-hash → aman di-cache lama).
 * - Gambar (/_next/image, /images/*): stale-while-revalidate, dibatasi 80 entri.
 * - Halaman publik (navigasi HTML): network-first → salinan cache → halaman /offline.
 * - Area per-pengguna (/portal, /trainer, /login, /register, /daftar, /api, aksi server, RSC): TIDAK di-cache
 *   (data pribadi tidak boleh tertinggal di perangkat bersama); bila offline navigasi jatuh ke /offline.
 *   /verifikasi juga tidak di-cache agar status sertifikat (mis. dicabut) tidak pernah basi.
 * - Permintaan lintas origin (API Laravel, CDN, YouTube) tidak disentuh.
 *
 * Naikkan VERSION saat strategi berubah; cache versi lama dihapus saat activate.
 * v2: notifikasi push (push / notificationclick).
 */
const VERSION = "v2";
const STATIC_CACHE = `ppti-static-${VERSION}`;
const PAGE_CACHE = `ppti-pages-${VERSION}`;
const IMAGE_CACHE = `ppti-images-${VERSION}`;
const OFFLINE_URL = "/offline";
const PRECACHE = [OFFLINE_URL, "/icons/icon-192.png", "/icons/icon-512.png", "/images/logo.jpeg", "/favicon.jpg"];
const PRIVATE_PREFIXES = ["/portal", "/trainer", "/login", "/register", "/daftar", "/lupa-password", "/reset-password", "/api/", "/verifikasi"];
const MAX_PAGES = 40;
const MAX_IMAGES = 80;

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(STATIC_CACHE)
      .then((cache) => cache.addAll(PRECACHE.map((url) => new Request(url, { cache: "reload" }))))
  );
});

self.addEventListener("activate", (event) => {
  const keep = [STATIC_CACHE, PAGE_CACHE, IMAGE_CACHE];
  event.waitUntil(
    (async () => {
      const names = await caches.keys();
      await Promise.all(names.filter((n) => n.startsWith("ppti-") && !keep.includes(n)).map((n) => caches.delete(n)));
      if (self.registration.navigationPreload) await self.registration.navigationPreload.enable();
      await self.clients.claim();
    })()
  );
});

self.addEventListener("message", (event) => {
  if (event.data === "SKIP_WAITING") self.skipWaiting();
  // Dipanggil saat logout: bersihkan salinan halaman (jaga-jaga bila header memuat nama pengguna)
  if (event.data === "CLEAR_PAGES") event.waitUntil(caches.delete(PAGE_CACHE));
});

function isPrivate(pathname) {
  return PRIVATE_PREFIXES.some((p) => (p.endsWith("/") ? pathname.startsWith(p) : pathname === p || pathname.startsWith(p + "/")));
}

async function trim(cacheName, max) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - max; i++) await cache.delete(keys[i]);
}

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  const response = await fetch(request);
  if (response.ok) (await caches.open(STATIC_CACHE)).put(request, response.clone());
  return response;
}

async function staleWhileRevalidate(event) {
  const cache = await caches.open(IMAGE_CACHE);
  const cached = await cache.match(event.request);
  const network = fetch(event.request)
    .then((response) => {
      if (response.ok) {
        cache.put(event.request, response.clone()).then(() => trim(IMAGE_CACHE, MAX_IMAGES));
      }
      return response;
    })
    .catch(() => cached);
  if (cached) {
    event.waitUntil(network);
    return cached;
  }
  return network;
}

async function offlinePage() {
  return (await caches.match(OFFLINE_URL)) || new Response("Anda sedang offline.", { status: 503, headers: { "Content-Type": "text/plain; charset=utf-8" } });
}

async function navigation(event, url) {
  const privateArea = isPrivate(url.pathname);
  try {
    const preload = await event.preloadResponse;
    const response = preload || (await fetch(event.request));
    // Hanya halaman publik 200 non-redirect yang disalin untuk dibuka offline
    if (!privateArea && response.ok && response.type === "basic" && !response.redirected) {
      const copy = response.clone();
      event.waitUntil(
        caches
          .open(PAGE_CACHE)
          .then((cache) => cache.put(url.pathname + url.search, copy))
          .then(() => trim(PAGE_CACHE, MAX_PAGES))
      );
    }
    return response;
  } catch {
    if (!privateArea) {
      const cached = await caches.match(url.pathname + url.search, { cacheName: PAGE_CACHE });
      if (cached) return cached;
    }
    return offlinePage();
  }
}

// ---- Web Push (PRD §5.12): payload JSON {title, body, url, tag, icon, badge} dari backend (SystemNotification::toWebPush)
self.addEventListener("push", (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = { body: event.data ? event.data.text() : "" };
  }
  const title = data.title || "PPTI VMES";
  event.waitUntil(
    self.registration.showNotification(title, {
      body: data.body || "",
      icon: data.icon || "/icons/icon-192.png",
      badge: data.badge || "/icons/shortcut-96.png",
      tag: data.tag || undefined,
      renotify: Boolean(data.tag),
      lang: "id",
      data: { url: typeof data.url === "string" && data.url.startsWith("/") ? data.url : "/portal/notifikasi" },
    })
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || "/portal/notifikasi", self.location.origin).href;
  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
      const same = windows.find((w) => new URL(w.url).origin === self.location.origin);
      if (same) {
        await same.focus();
        return same.navigate(target);
      }
      return self.clients.openWindow(target);
    })()
  );
});

self.addEventListener("fetch", (event) => {
  const { request } = event;
  if (request.method !== "GET") return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  // Payload RSC / prefetch Next ditangani router Next (experimental.useOffline), bukan SW
  if (request.headers.get("RSC") || url.searchParams.has("_rsc") || request.headers.get("Next-Router-Prefetch")) return;

  if (request.mode === "navigate") {
    event.respondWith(navigation(event, url));
    return;
  }
  if (isPrivate(url.pathname)) return;
  if (url.pathname.startsWith("/_next/static/") || url.pathname.startsWith("/icons/") || url.pathname === "/favicon.jpg") {
    event.respondWith(cacheFirst(request));
    return;
  }
  if (url.pathname.startsWith("/_next/image") || url.pathname.startsWith("/images/")) {
    event.respondWith(staleWhileRevalidate(event));
  }
});
