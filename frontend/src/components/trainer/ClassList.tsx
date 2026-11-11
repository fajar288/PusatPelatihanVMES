import Link from "next/link";
import { EmptyState, MetaList, PortalCard } from "@/components/portal/ui";
import { CLASS_STATUS, type TrainerClass } from "@/lib/trainer";

/** Daftar kelas trainer sebagai pintu masuk fitur per kelas (materi, tugas, kuis, logbook, penilaian, progres). */
export default function ClassList({ title, intro, classes, base, cta }: { title: string; intro: string; classes: TrainerClass[]; base: string; cta: string }) {
  return (
    <PortalCard title={title}>
      <p className="fh" style={{ marginTop: 4 }}>
        {intro}
      </p>
      {classes.length ? (
        <div className="kjl" style={{ marginTop: 16 }}>
          {classes.map((c) => (
            <div className="kj" key={c.code}>
              <div>
                <b>{c.title}</b>
                <MetaList
                  compact
                  items={[
                    { icon: "code", value: c.code },
                    { icon: "cal", value: `${c.dateLabel} – ${c.endLabel}` },
                    { icon: "users", value: `${c.participantsCount} peserta` },
                    { value: `${c.sessionsCount} sesi` },
                  ]}
                />
              </div>
              <div className="kjr">
                <span className={"jst " + (c.status === "cancelled" ? "no" : c.status === "ongoing" ? "wn" : "ok")}>{CLASS_STATUS[c.status] ?? c.status}</span>
                <Link className="jb" href={`${base}/${c.code}`}>
                  {cta}
                </Link>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <EmptyState text="Belum ada kelas yang ditugaskan kepada Anda." />
      )}
    </PortalCard>
  );
}
