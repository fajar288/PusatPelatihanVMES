"use client";

import { useEffect, useRef, useState } from "react";
import { videoProgressAction } from "@/lib/actions/lms";
import { MetaList } from "@/components/portal/ui";
import type { LessonDetail } from "@/lib/lms";

const HEARTBEAT = 15; // detik

/**
 * Pemutar video dengan resume & tracking progres (PRD §5.8).
 * - File MP4/WebM: <video> native, posisi & % ditonton dikirim tiap 15 detik dan saat jeda.
 * - YouTube/Vimeo (iframe, tak bisa dibaca posisinya): % dihitung dari waktu menonton di halaman ÷ durasi.
 */
export default function VideoPlayer({ lesson }: { lesson: LessonDetail }) {
  const v = lesson.video!;
  const [percent, setPercent] = useState(lesson.progress.videoPercent);
  const [status, setStatus] = useState(lesson.progress.status);
  const ref = useRef<HTMLVideoElement>(null);
  const maxSeen = useRef(lesson.progress.videoPosition);
  const spent = useRef(0);
  const lastSent = useRef(0);

  const send = async (position: number, pct: number) => {
    const s = spent.current;
    spent.current = 0;
    const r = await videoProgressAction(lesson.course.slug, lesson.id, Math.floor(position), Math.floor(pct), s);
    if (r.ok) {
      setPercent(r.data.videoPercent);
      setStatus(r.data.status as typeof status);
    }
  };

  // file: ambil posisi dari elemen video
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (lesson.progress.videoPosition > 0) el.currentTime = lesson.progress.videoPosition;
    const tick = () => {
      if (el.paused) return;
      spent.current += 1;
      maxSeen.current = Math.max(maxSeen.current, el.currentTime);
      const pct = el.duration ? (maxSeen.current / el.duration) * 100 : 0;
      if (Date.now() - lastSent.current > HEARTBEAT * 1000) {
        lastSent.current = Date.now();
        void send(el.currentTime, pct);
      }
    };
    const id = window.setInterval(tick, 1000);
    const onPause = () => void send(el.currentTime, el.duration ? (maxSeen.current / el.duration) * 100 : 0);
    const onEnded = () => void send(el.duration, 100);
    el.addEventListener("pause", onPause);
    el.addEventListener("ended", onEnded);
    return () => {
      window.clearInterval(id);
      el.removeEventListener("pause", onPause);
      el.removeEventListener("ended", onEnded);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // embed: hitung dari waktu di halaman (tab aktif)
  useEffect(() => {
    if (v.provider === "file" || !v.durationSeconds) return;
    let watched = maxSeen.current;
    const id = window.setInterval(() => {
      if (document.hidden) return;
      watched += 1;
      spent.current += 1;
      if (watched % HEARTBEAT === 0) void send(watched, (watched / v.durationSeconds!) * 100);
    }, 1000);
    return () => window.clearInterval(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="lvideo">
      {v.provider === "file" && v.url ? (
        <video ref={ref} controls playsInline preload="metadata" poster={v.thumbnail ?? undefined} src={v.url} />
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
      {v.provider !== "file" && !v.durationSeconds && (
        <p className="fh" style={{ marginTop: 4, fontSize: 13 }}>
          Durasi video belum diatur. Tandai selesai secara manual setelah menonton.
        </p>
      )}
    </div>
  );
}
