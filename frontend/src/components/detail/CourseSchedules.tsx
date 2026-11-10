import Link from "next/link";
import type { Schedule } from "@/data/schedules";
import { formatRupiah, waLink, waRegisterText } from "@/lib/utils";

/** section.kbx > .kjl > .kj — daftar jadwal batch untuk satu kursus (CSS .kj/.kjr/.kem dari tema asli). */
export default function CourseSchedules({ schedules, title }: { schedules: Schedule[]; title: string }) {
  return (
    <section className="kbx">
      <h2>Jadwal Terdekat</h2>
      {schedules.length ? (
        <div className="kjl">
          {schedules.map((s) => (
            <div className="kj" key={s.id}>
              <div>
                <b>{s.dateLabel}</b>
                <p>
                  {s.city} • {s.mode} • {s.durationDays} Hari
                </p>
              </div>
              <div className="kjr">
                <span className={"jst " + s.status}>{s.statusLabel}</span>
                <strong>{formatRupiah(s.price)}</strong>
                <a className="jb" target="_blank" rel="noopener" href={waLink(waRegisterText(s.title, s.dateLabel))}>
                  Daftar
                </a>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <p className="kem" style={{ marginTop: 16 }}>
          Belum ada jadwal terbuka untuk {title}. <Link href="/jadwal">Lihat semua jadwal</Link> atau hubungi kami untuk
          jadwal in-house / private.
        </p>
      )}
    </section>
  );
}
