import Link from "next/link";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api } from "@/lib/auth";
import { CLASS_STATUS, requireTrainer, ROLE_LABEL, type TrainerClass } from "@/lib/trainer";

export const instant = false;

/** /trainer/kelas — My Classes. */
export default async function TrainerClassesPage() {
  const { token } = await requireTrainer("/trainer/kelas");
  const classes = (await api<{ data: TrainerClass[] }>("/trainer/classes", { token })).data;
  return (
    <PortalCard title="Kelas Saya">
      {classes.length ? (
        <div className="jt" style={{ marginTop: 16 }}>
          {classes.map((c) => (
            <div className="jr prow" key={c.code}>
              <div className="j1">
                <Link href={`/trainer/kelas/${c.code}`}>
                  <h3>{c.title}</h3>
                </Link>
                <p>
                  {c.code} • {c.city} • {c.mode} • {ROLE_LABEL[c.myRole ?? ""] ?? c.myRole}
                </p>
              </div>
              <div className="j2">
                <b>{c.dateLabel}</b>
                <span>
                  {c.sessionsCount} sesi • {c.participantsCount} peserta
                </span>
              </div>
              <div className="j3">
                <span className={"jst " + (c.status === "cancelled" ? "no" : c.status === "ongoing" ? "wn" : "ok")}>{CLASS_STATUS[c.status] ?? c.status}</span>
              </div>
              <Link className="jb" href={`/trainer/kelas/${c.code}`}>
                Buka
              </Link>
            </div>
          ))}
        </div>
      ) : (
        <EmptyState text="Belum ada kelas yang ditugaskan kepada Anda." />
      )}
    </PortalCard>
  );
}
