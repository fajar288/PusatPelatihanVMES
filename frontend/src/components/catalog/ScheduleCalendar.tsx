"use client";

import Link from "next/link";
import { useMemo, useState, useSyncExternalStore } from "react";
import { MONTHS_ID, type Schedule } from "@/data/schedules";

const DAYS = ["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"];

const iso = (y: number, m: number, d: number) => `${y}-${String(m + 1).padStart(2, "0")}-${String(d).padStart(2, "0")}`;

/** Kalender bulanan jadwal training (PRD §5.4). Bulan awal = bulan jadwal terdekat, bisa digeser. */
export default function ScheduleCalendar({ schedules }: { schedules: Schedule[] }) {
  const firstStart = useMemo(() => [...schedules].sort((a, b) => a.startDate.localeCompare(b.startDate))[0]?.startDate, [schedules]);
  const [ym, setYm] = useState(() => {
    const src = firstStart ?? "2026-01-01";
    return { y: Number(src.slice(0, 4)), m: Number(src.slice(5, 7)) - 1 };
  });
  // `new Date()` tidak boleh dipakai saat prerender (cacheComponents): server render "" → klien mengisi tanggal hari ini.
  const today = useSyncExternalStore(() => () => {}, () => new Date().toISOString().slice(0, 10), () => "");

  const cells = useMemo(() => {
    const first = new Date(Date.UTC(ym.y, ym.m, 1));
    const lead = (first.getUTCDay() + 6) % 7; // Senin = 0
    const dim = new Date(Date.UTC(ym.y, ym.m + 1, 0)).getUTCDate();
    const prevDim = new Date(Date.UTC(ym.y, ym.m, 0)).getUTCDate();
    const out: { key: string; day: number; off: boolean; date: string }[] = [];
    for (let i = lead - 1; i >= 0; i--) out.push({ key: "p" + i, day: prevDim - i, off: true, date: iso(ym.y, ym.m - 1, prevDim - i) });
    for (let d = 1; d <= dim; d++) out.push({ key: "d" + d, day: d, off: false, date: iso(ym.y, ym.m, d) });
    for (let d = 1; out.length % 7 !== 0; d++) out.push({ key: "n" + d, day: d, off: true, date: iso(ym.y, ym.m + 1, d) });
    return out;
  }, [ym]);

  const shift = (n: number) => setYm(({ y, m }) => ({ y: y + Math.floor((m + n) / 12), m: ((m + n) % 12 + 12) % 12 }));

  return (
    <section className="cal" aria-label="Kalender training">
      <div className="calh">
        <button className="pn" type="button" onClick={() => shift(-1)}>
          &#8592; Sebelumnya
        </button>
        <h2>
          Kalender Training — {MONTHS_ID[ym.m]} {ym.y}
        </h2>
        <button className="pn" type="button" onClick={() => shift(1)}>
          Berikutnya &#8594;
        </button>
      </div>
      <div className="calg">
        {DAYS.map((d) => (
          <div className="cald" key={d}>
            {d}
          </div>
        ))}
        {cells.map((c) => {
          const items = schedules.filter((s) => c.date >= s.startDate && c.date <= s.endDate);
          return (
            <div className={"calc" + (c.off ? " off" : "") + (c.date === today ? " td" : "")} key={c.key}>
              <span>{c.day}</span>
              {items.map((s) => (
                <Link key={s.id} className={s.status} href={`/jadwal/${s.id}`} title={`${s.title} (${s.city})`}>
                  {s.title}
                </Link>
              ))}
            </div>
          );
        })}
      </div>
      <div className="call">
        <span>
          <i style={{ background: "rgba(0,75,35,.25)" }} />
          Tersedia
        </span>
        <span>
          <i style={{ background: "#f1dc9b" }} />
          Terbatas
        </span>
        <span>
          <i style={{ background: "#ffd2ab" }} />
          Penuh
        </span>
      </div>
    </section>
  );
}
