"use client";

import { useOffline } from "next/offline";
import { useEffect, useState } from "react";

declare global {
  interface Window {
    __pptiSwReload?: boolean;
  }
}

/** Daftarkan service worker hanya di build produksi (dev: HMR & cache tidak saling ganggu) atau bila dipaksa lewat env. */
const SW_ENABLED = process.env.NODE_ENV === "production" || process.env.NEXT_PUBLIC_ENABLE_SW === "1";

/**
 * PWA (Phase 5): registrasi `/sw.js`, notifikasi "versi baru tersedia", dan banner offline.
 * `useOffline` (experimental.useOffline) juga menahan navigasi/aksi server saat offline lalu mengulanginya otomatis.
 */
export default function PwaProvider() {
  const offline = useOffline();
  const [waiting, setWaiting] = useState<ServiceWorker | null>(null);

  useEffect(() => {
    if (!SW_ENABLED || !("serviceWorker" in navigator)) return;
    let reloading = false;
    let interval: number | undefined;
    // Reload hanya setelah pengguna memilih "Muat ulang" (SKIP_WAITING); SW pertama kali (clients.claim) juga memicu
    // controllerchange dan tidak boleh me-reload halaman.
    const onControllerChange = () => {
      if (reloading || !window.__pptiSwReload) return;
      reloading = true;
      window.location.reload();
    };

    navigator.serviceWorker
      .register("/sw.js", { scope: "/", updateViaCache: "none" })
      .then((reg) => {
        // Hanya tawarkan pembaruan bila sudah ada SW lama yang mengendalikan halaman
        if (reg.waiting && navigator.serviceWorker.controller) setWaiting(reg.waiting);
        reg.addEventListener("updatefound", () => {
          const sw = reg.installing;
          sw?.addEventListener("statechange", () => {
            if (sw.state === "installed" && navigator.serviceWorker.controller) setWaiting(sw);
          });
        });
        // tab yang lama terbuka tetap memeriksa pembaruan tiap jam
        interval = window.setInterval(() => reg.update().catch(() => {}), 60 * 60 * 1000);
      })
      .catch(() => {});
    navigator.serviceWorker.addEventListener("controllerchange", onControllerChange);
    return () => {
      window.clearInterval(interval);
      navigator.serviceWorker.removeEventListener("controllerchange", onControllerChange);
    };
  }, []);

  return (
    <>
      {offline && (
        <div className="pwa-bar is-off" role="status" aria-live="polite">
          <span>Anda sedang offline. Halaman yang pernah dibuka tetap tersedia; aksi tertunda akan dikirim ulang saat koneksi kembali.</span>
        </div>
      )}
      {waiting && !offline && (
        <div className="pwa-bar" role="status" aria-live="polite">
          <span>Versi baru situs tersedia.</span>
          <button
            type="button"
            onClick={() => {
              window.__pptiSwReload = true;
              waiting.postMessage("SKIP_WAITING");
            }}
          >
            Muat ulang
          </button>
          <button type="button" className="ghost" onClick={() => setWaiting(null)} aria-label="Tutup">
            Nanti
          </button>
        </div>
      )}
    </>
  );
}

/** Hapus salinan halaman di cache SW (dipanggil saat logout). */
export function clearPwaPageCache() {
  if (typeof navigator !== "undefined" && navigator.serviceWorker?.controller) {
    navigator.serviceWorker.controller.postMessage("CLEAR_PAGES");
  }
}
