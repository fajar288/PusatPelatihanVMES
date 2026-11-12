"use client";

import { useEffect, useRef, useState } from "react";
import { videoProgressAction } from "@/lib/actions/lms";
import { MetaList } from "@/components/portal/ui";
import type { LessonDetail } from "@/lib/lms";

const HEARTBEAT = 15; // detik
/** Lompatan maksimum antar-tick (1 detik) yang masih dianggap menonton biasa (kecepatan putar s.d. 2x + jeda tick). */
const MAX_STEP = 3;

/* --- YouTube IFrame Player API (tipe minimal yang dipakai) --- */
type YTPlayer = { getCurrentTime(): number; getDuration(): number; getPlayerState(): number; destroy(): void };
type YTNamespace = {
  Player: new (
    el: HTMLElement,
    opts: {
      videoId: string;
      playerVars?: Record<string, string | number>;
      events?: { onReady?: () => void; onStateChange?: (e: { data: number }) => void };
    },
  ) => YTPlayer;
  PlayerState: { ENDED: number; PLAYING: number; PAUSED: number };
};
declare global {
  interface Window {
    YT?: YTNamespace;
    onYouTubeIframeAPIReady?: () => void;
  }
}

let ytApi: Promise<YTNamespace> | null = null;

/** Muat https://www.youtube.com/iframe_api sekali per halaman. */
function loadYouTubeApi(): Promise<YTNamespace> {
  if (window.YT?.Player) return Promise.resolve(window.YT);
  ytApi ??= new Promise<YTNamespace>((resolve, reject) => {
    const prev = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = () => {
      prev?.();
      resolve(window.YT!);
    };
    const s = document.createElement("script");
    s.src = "https://www.youtube.com/iframe_api";
    s.async = true;
    s.onerror = () => {
      ytApi = null;
      reject(new Error("YouTube API gagal dimuat"));
    };
    document.head.appendChild(s);
  });
  return ytApi;
}

/** ID video dari URL embed `https://www.youtube.com/embed/<id>?rel=0`. */
function youtubeId(embedUrl: string | null): string | null {
  return embedUrl?.match(/youtube\.com\/embed\/([A-Za-z0-9_-]{6,})/)?.[1] ?? null;
}

/**
 * Pemutar video dengan resume & tracking progres (PRD §5.8).
 * - File MP4/WebM (<video>) dan YouTube (IFrame Player API): posisi dibaca dari player. Persentase = titik terjauh
 *   yang dicapai dengan menonton berurutan; melompat (seek) ke depan tidak menambah persentase. Dikirim tiap 15 detik,
 *   saat jeda, dan saat selesai. Video dilanjutkan dari posisi terakhir.
 * - Vimeo / bila API YouTube gagal dimuat: iframe biasa, % dihitung dari waktu menonton di halaman ÷ durasi.
 */
export default function VideoPlayer({ lesson }: { lesson: LessonDetail }) {
  const v = lesson.video!;
  const ytId = v.provider === "youtube" ? youtubeId(v.embedUrl) : null;
  const [percent, setPercent] = useState(lesson.progress.videoPercent);
  const [status, setStatus] = useState(lesson.progress.status);
  const [ytFailed, setYtFailed] = useState(false);
  const fileRef = useRef<HTMLVideoElement>(null);
  const ytRef = useRef<HTMLDivElement>(null);
  const spent = useRef(0);
  const lastSent = useRef(0);

  const send = async (position: number, pct: number) => {
    const s = spent.current;
    spent.current = 0;
    lastSent.current = Date.now();
    const r = await videoProgressAction(lesson.course.slug, lesson.id, Math.floor(position), Math.floor(Math.min(100, pct)), s);
    if (r.ok) {
      setPercent(r.data.videoPercent);
      setStatus(r.data.status as typeof status);
    }
  };

  /**
   * Pelacak bersama untuk player yang posisinya bisa dibaca. `read()` mengembalikan posisi & durasi (detik) atau null.
   * Titik terjauh awal = maks(posisi tersimpan, % tersimpan × durasi) agar progres sesi sebelumnya tidak hilang.
   */
  const makeTracker = (read: () => { time: number; duration: number } | null) => {
    let last: number | null = null;
    let furthest = lesson.progress.videoPosition;
    let seeded = false;
    const pct = (duration: number) => (duration > 0 ? (furthest / duration) * 100 : 0);
    return {
      /** Dipanggil tiap detik saat video sedang diputar. */
      tick() {
        const r = read();
        if (!r) return;
        if (!seeded && r.duration > 0) {
          furthest = Math.max(furthest, (lesson.progress.videoPercent / 100) * r.duration);
          seeded = true;
        }
        spent.current += 1;
        if (last !== null) {
          const step = r.time - last;
          if (step > 0 && step <= MAX_STEP && last <= furthest + MAX_STEP) furthest = Math.max(furthest, r.time);
        }
        last = r.time;
        if (Date.now() - lastSent.current > HEARTBEAT * 1000) void send(r.time, pct(r.duration));
      },
      /** Jeda/selesai: kirim segera. Saat selesai tanpa melompat, anggap 100%. */
      flush(ended = false) {
        const r = read();
        if (!r) return;
        if (ended && last !== null && last >= furthest - MAX_STEP) furthest = r.duration;
        last = null;
        void send(r.time, pct(r.duration));
      },
    };
  };

  // File: elemen <video>
  useEffect(() => {
    const el = fileRef.current;
    if (!el) return;
    const resumeAt = lesson.progress.videoPosition;
    const onMeta = () => {
      if (resumeAt > 0 && resumeAt < el.duration - 5) el.currentTime = resumeAt;
    };
    el.addEventListener("loadedmetadata", onMeta);
    const t = makeTracker(() => (el.duration ? { time: el.currentTime, duration: el.duration } : null));
    const id = window.setInterval(() => {
      if (!el.paused) t.tick();
    }, 1000);
    const onPause = () => t.flush();
    const onEnded = () => t.flush(true);
    el.addEventListener("pause", onPause);
    el.addEventListener("ended", onEnded);
    return () => {
      window.clearInterval(id);
      el.removeEventListener("loadedmetadata", onMeta);
      el.removeEventListener("pause", onPause);
      el.removeEventListener("ended", onEnded);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // YouTube: IFrame Player API
  useEffect(() => {
    if (!ytId || !ytRef.current) return;
    let player: YTPlayer | null = null;
    let interval = 0;
    let cancelled = false;
    const host = ytRef.current;
    loadYouTubeApi()
      .then((YT) => {
        if (cancelled) return;
        const mount = document.createElement("div");
        host.replaceChildren(mount);
        const resumeAt = lesson.progress.videoPosition;
        const t = makeTracker(() => {
          const d = player?.getDuration() ?? 0;
          return d > 0 ? { time: player!.getCurrentTime(), duration: d } : null;
        });
        player = new YT.Player(mount, {
          videoId: ytId,
          playerVars: { rel: 0, playsinline: 1, modestbranding: 1, ...(resumeAt > 5 ? { start: Math.floor(resumeAt) } : {}) },
          events: {
            onStateChange: (e) => {
              if (e.data === YT.PlayerState.PAUSED) t.flush();
              else if (e.data === YT.PlayerState.ENDED) t.flush(true);
            },
          },
        });
        interval = window.setInterval(() => {
          if (player?.getPlayerState?.() === YT.PlayerState.PLAYING) t.tick();
        }, 1000);
      })
      .catch(() => {
        if (!cancelled) setYtFailed(true);
      });
    return () => {
      cancelled = true;
      window.clearInterval(interval);
      player?.destroy();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ytId]);

  // Iframe biasa (Vimeo / API YouTube gagal): hitung dari waktu di halaman (tab aktif)
  const plainEmbed = v.provider !== "file" && (!ytId || ytFailed);
  useEffect(() => {
    if (!plainEmbed || !v.durationSeconds) return;
    let watched = Math.max(lesson.progress.videoPosition, Math.floor((lesson.progress.videoPercent / 100) * v.durationSeconds));
    const id = window.setInterval(() => {
      if (document.hidden) return;
      watched += 1;
      spent.current += 1;
      if (watched % HEARTBEAT === 0) void send(watched, (watched / v.durationSeconds!) * 100);
    }, 1000);
    return () => window.clearInterval(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [plainEmbed]);

  return (
    <div className="lvideo">
      {v.provider === "file" && v.url ? (
        <video ref={fileRef} controls playsInline preload="metadata" poster={v.thumbnail ?? undefined} src={v.url} />
      ) : ytId && !ytFailed ? (
        <div ref={ytRef} className="lvideo-yt" />
      ) : v.embedUrl ? (
        <iframe src={v.embedUrl} title={lesson.title} allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowFullScreen />
      ) : (
        <p className="fh">Video belum tersedia.</p>
      )}
      <MetaList
        compact
        style={{ marginTop: 10 }}
        items={[
          { icon: "monitor", label: "Ditonton", value: `${percent}%` },
          { label: "Selesai setelah", value: `${v.minWatchPct}%` },
          status === "completed" && { icon: "check", value: "Selesai" },
        ]}
      />
      {plainEmbed && !v.durationSeconds && (
        <p className="fh" style={{ marginTop: 4, fontSize: 13 }}>
          Durasi video belum diatur. Tandai selesai secara manual setelah menonton.
        </p>
      )}
    </div>
  );
}
