"use client";

import { useEffect, useState, useSyncExternalStore } from "react";

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
};

const DISMISS_KEY = "ppti-pwa-install-dismissed";

function readDismissed(): boolean {
  try {
    const at = Number(window.localStorage.getItem(DISMISS_KEY) || 0);
    return Date.now() - at < 30 * 24 * 60 * 60 * 1000; // sembunyikan 30 hari setelah ditutup
  } catch {
    return false;
  }
}

/** Platform: "ios" (Safari tanpa beforeinstallprompt), "standalone" (sudah terpasang), atau "" (lainnya / server). */
function platform(): string {
  if (window.matchMedia("(display-mode: standalone)").matches || (navigator as Navigator & { standalone?: boolean }).standalone) return "standalone";
  return /iphone|ipad|ipod/i.test(navigator.userAgent) && !/crios|fxios/i.test(navigator.userAgent) ? "ios" : "";
}

const noop = () => () => {};

/**
 * Kartu "Pasang aplikasi" di portal member & trainer (PWA). Chrome/Edge/Android memakai prompt bawaan
 * (`beforeinstallprompt`); iOS Safari mendapat petunjuk Bagikan → Tambah ke Layar Utama.
 */
export default function InstallPrompt() {
  const env = useSyncExternalStore(noop, platform, () => "");
  const [deferred, setDeferred] = useState<BeforeInstallPromptEvent | null>(null);
  const [hidden, setHidden] = useState(true);

  useEffect(() => {
    // localStorage hanya terbaca di klien → tampilkan setelah mount agar hidrasi konsisten
    const t = window.setTimeout(() => setHidden(readDismissed()), 0);
    const onPrompt = (e: Event) => {
      e.preventDefault();
      setDeferred(e as BeforeInstallPromptEvent);
    };
    const onInstalled = () => setDeferred(null);
    window.addEventListener("beforeinstallprompt", onPrompt);
    window.addEventListener("appinstalled", onInstalled);
    return () => {
      window.clearTimeout(t);
      window.removeEventListener("beforeinstallprompt", onPrompt);
      window.removeEventListener("appinstalled", onInstalled);
    };
  }, []);

  if (hidden || env === "standalone" || (!deferred && env !== "ios")) return null;

  const dismiss = () => {
    try {
      window.localStorage.setItem(DISMISS_KEY, String(Date.now()));
    } catch {}
    setHidden(true);
  };

  const install = async () => {
    if (!deferred) return;
    await deferred.prompt();
    const { outcome } = await deferred.userChoice;
    setDeferred(null);
    if (outcome === "dismissed") dismiss();
  };

  return (
    <div className="pwa-inst" role="region" aria-label="Pasang aplikasi">
      <img src="/icons/icon-192.png" alt="" width={44} height={44} />
      <div>
        <b>Pasang aplikasi PPTI VMES</b>
        {env === "ios" ? (
          <p>
            Ketuk tombol <strong>Bagikan</strong> di Safari, lalu pilih <strong>Tambah ke Layar Utama</strong> untuk membuka portal seperti aplikasi.
          </p>
        ) : (
          <p>Akses jadwal, materi belajar, dan sertifikat langsung dari layar utama perangkat Anda.</p>
        )}
      </div>
      <div className="pwa-inst-act">
        {deferred && (
          <button type="button" className="btn" onClick={install}>
            Pasang
          </button>
        )}
        <button type="button" className="pwa-x" onClick={dismiss} aria-label="Tutup">
          ×
        </button>
      </div>
    </div>
  );
}
