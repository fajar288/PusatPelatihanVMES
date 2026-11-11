"use client";

import { useEffect, useState, useTransition } from "react";
import { subscribePushAction, testPushAction, unsubscribePushAction } from "@/lib/actions/notifications";

type State = "loading" | "unsupported" | "server-off" | "denied" | "off" | "on";

function base64UrlToUint8Array(b64: string): Uint8Array<ArrayBuffer> {
  const padded = (b64 + "=".repeat((4 - (b64.length % 4)) % 4)).replace(/-/g, "+").replace(/_/g, "/");
  const raw = window.atob(padded);
  const out = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}

async function registration(): Promise<ServiceWorkerRegistration> {
  // SW biasanya sudah didaftarkan PwaProvider (produksi); di dev daftarkan saat pengguna mengaktifkan push
  const existing = await navigator.serviceWorker.getRegistration("/");
  return existing ?? navigator.serviceWorker.register("/sw.js", { scope: "/", updateViaCache: "none" });
}

/** Aktif/nonaktifkan Web Push di perangkat ini + kirim notifikasi uji (PRD §5.12, PWA). */
export default function PushToggle({ publicKey, available }: { publicKey: string | null; available: boolean }) {
  const [state, setState] = useState<State>("loading");
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [pending, start] = useTransition();

  useEffect(() => {
    let cancelled = false;
    (async () => {
      let next: State;
      if (!("serviceWorker" in navigator) || !("PushManager" in window) || !("Notification" in window)) next = "unsupported";
      else if (!available || !publicKey) next = "server-off";
      else if (Notification.permission === "denied") next = "denied";
      else {
        const reg = await navigator.serviceWorker.getRegistration("/");
        next = (await reg?.pushManager.getSubscription()) ? "on" : "off";
      }
      if (!cancelled) setState(next);
    })().catch(() => !cancelled && setState("off"));
    return () => {
      cancelled = true;
    };
  }, [available, publicKey]);

  const enable = () =>
    start(async () => {
      setMsg(null);
      try {
        const permission = await Notification.requestPermission();
        if (permission !== "granted") {
          setState(permission === "denied" ? "denied" : "off");
          setMsg({ ok: false, text: "Izin notifikasi tidak diberikan." });
          return;
        }
        const reg = await registration();
        await navigator.serviceWorker.ready;
        const sub =
          (await reg.pushManager.getSubscription()) ??
          (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64UrlToUint8Array(publicKey!) }));
        const r = await subscribePushAction(sub.toJSON());
        if (r.ok) {
          setState("on"); // teks status sudah menyatakan push aktif
        } else {
          setMsg({ ok: false, text: r.message });
          await sub.unsubscribe();
        }
      } catch {
        setMsg({ ok: false, text: "Gagal mengaktifkan notifikasi push di browser ini." });
      }
    });

  const disable = () =>
    start(async () => {
      setMsg(null);
      const reg = await navigator.serviceWorker.getRegistration("/");
      const sub = await reg?.pushManager.getSubscription();
      if (sub) {
        const r = await unsubscribePushAction(sub.endpoint);
        await sub.unsubscribe();
        setMsg({ ok: r.ok, text: r.message });
      }
      setState("off");
    });

  const test = () =>
    start(async () => {
      const r = await testPushAction();
      setMsg({ ok: r.ok, text: r.message });
    });

  const info: Record<State, string> = {
    loading: "Memeriksa dukungan notifikasi push…",
    unsupported: "Browser ini belum mendukung notifikasi push. Di iPhone/iPad, pasang aplikasi ke Layar Utama terlebih dahulu (iOS 16.4+).",
    "server-off": "Notifikasi push belum diaktifkan oleh PPTI.",
    denied: "Notifikasi diblokir untuk situs ini. Izinkan lewat pengaturan situs di browser, lalu muat ulang halaman.",
    off: "Terima notifikasi di perangkat ini walau portal sedang tidak dibuka.",
    on: "Notifikasi push aktif di perangkat ini.",
  };

  return (
    <div className="npush">
      <div>
        <b>Notifikasi push di perangkat ini</b>
        <p>{info[state]}</p>
        {msg && <p className={msg.ok ? "npush-ok" : "npush-err"}>{msg.text}</p>}
      </div>
      <div className="npush-act">
        {state === "off" && (
          <button type="button" className="btn" onClick={enable} disabled={pending}>
            {pending ? "Memproses…" : "Aktifkan"}
          </button>
        )}
        {state === "on" && (
          <>
            <button type="button" className="btn bl" onClick={test} disabled={pending}>
              Kirim uji
            </button>
            <button type="button" className="btn bl" onClick={disable} disabled={pending}>
              Matikan
            </button>
          </>
        )}
      </div>
    </div>
  );
}
