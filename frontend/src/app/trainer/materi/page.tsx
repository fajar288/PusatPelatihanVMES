import Link from "next/link";
import { EmptyState, MetaList, PortalCard } from "@/components/portal/ui";
import { api } from "@/lib/auth";
import { CLASS_STATUS, requireTrainer, type TrainerClass } from "@/lib/trainer";

export const instant = false;

/** /trainer/materi — Materials: pilih kelas untuk melihat course/materi & progres belajar peserta. */
export default async function TrainerMaterialsPage() {
  const { token } = await requireTrainer("/trainer/materi");
  const classes = (await api<{ data: TrainerClass[] }>("/trainer/classes", { token })).data;
  return (
    <PortalCard title="Materi & Progres Belajar">
      <p className="fh" style={{ marginTop: 4 }}>
        Materi (course, modul, lesson) dikelola admin di panel admin. Di sini Anda melihat materi tiap kelas dan progres belajar peserta.
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
                  ]}
                />
              </div>
              <div className="kjr">
                <span className={"jst " + (c.status === "cancelled" ? "no" : "ok")}>{CLASS_STATUS[c.status] ?? c.status}</span>
                <Link className="jb" href={`/trainer/materi/${c.code}`}>
                  Lihat materi
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
