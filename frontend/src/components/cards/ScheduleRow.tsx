import Link from "next/link";
import type { Schedule } from "@/data/schedules";
import { formatRupiah, waLink, waRegisterText } from "@/lib/utils";

/** div.jr — baris jadwal terdekat. Tombol "Daftar" membuka WA dengan prefill nama + tanggal. */
export default function ScheduleRow({ s, hidden }: { s: Schedule; hidden?: boolean }) {
  return (
    <div className="jr" hidden={hidden}>
      <div className="j1">
        <Link href={`/kursus/${s.courseSlug}`}>
          <h3>{s.title}</h3>
        </Link>
        <p>
          {s.city} • {s.mode}
        </p>
      </div>
      <div className="j2">
        <b>{s.dateLabel}</b>
        <span>{s.durationDays} Hari</span>
      </div>
      <div className="j3">
        <span className={"jst " + s.status}>{s.statusLabel}</span>
        <b>{formatRupiah(s.price)}</b>
      </div>
      <a className="jb" target="_blank" rel="noopener" href={waLink(waRegisterText(s.title, s.dateLabel))}>
        Daftar
      </a>
    </div>
  );
}
