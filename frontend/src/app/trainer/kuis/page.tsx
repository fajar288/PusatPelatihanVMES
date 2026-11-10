import Link from "next/link";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api } from "@/lib/auth";
import { requireTrainer, type TrainerClass } from "@/lib/trainer";

export const instant = false;

/** /trainer/kuis — Quiz Results: pilih kelas. */
export default async function TrainerQuizIndex() {
  const { token } = await requireTrainer("/trainer/kuis");
  const classes = (await api<{ data: TrainerClass[] }>("/trainer/classes", { token })).data;
  return (
    <PortalCard title="Hasil Kuis & Tes">
      {classes.length ? (
        <div className="kjl" style={{ marginTop: 16 }}>
          {classes.map((c) => (
            <div className="kj" key={c.code}>
              <div>
                <b>{c.title}</b>
                <p>
                  {c.code} • {c.dateLabel} • {c.participantsCount} peserta
                </p>
              </div>
              <div className="kjr">
                <Link className="jb" href={`/trainer/kuis/${c.code}`}>
                  Lihat hasil
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
