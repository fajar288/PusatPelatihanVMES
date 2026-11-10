import Link from "next/link";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api } from "@/lib/auth";
import { CLASS_STATUS, requireTrainer, ROLE_LABEL, type TrainerClass } from "@/lib/trainer";

export const instant = false;

/** /trainer — dashboard: ringkasan kelas aktif & mendatang. */
export default async function TrainerDashboard() {
  const { token, profile } = await requireTrainer("/trainer");
  const classes = (await api<{ data: TrainerClass[] }>("/trainer/classes", { token })).data;
  const today = new Date().toISOString().slice(0, 10);
  const upcoming = classes.filter((c) => c.endDate >= today && c.status !== "cancelled");
  const sessionsTotal = classes.reduce((n, c) => n + c.sessionsCount, 0);

  return (
    <>
      <div className="kic pstats">
        <div className="kic1">
          <small>Kelas aktif / mendatang</small>
          <b>{upcoming.length}</b>
        </div>
        <div className="kic1">
          <small>Total kelas ditugaskan</small>
          <b>{classes.length}</b>
        </div>
        <div className="kic1">
          <small>Total sesi</small>
          <b>{sessionsTotal}</b>
        </div>
        <div className="kic1">
          <small>Peran</small>
          <b>{profile.isAssessor ? "Trainer & Assessor" : "Trainer"}</b>
        </div>
      </div>
      <PortalCard
        title="Kelas mendatang"
        action={
          <Link className="lk" href="/trainer/kelas">
            Semua kelas
          </Link>
        }
      >
        {upcoming.length ? (
          <div className="kjl" style={{ marginTop: 16 }}>
            {upcoming.slice(0, 5).map((c) => (
              <div className="kj" key={c.code}>
                <div>
                  <b>{c.title}</b>
                  <p>
                    {c.code} • {c.dateLabel} – {c.endLabel} • {c.city} • {c.mode} • {c.participantsCount} peserta • {ROLE_LABEL[c.myRole ?? ""] ?? c.myRole}
                  </p>
                </div>
                <div className="kjr">
                  <span className={"jst " + (c.status === "ongoing" ? "wn" : "ok")}>{CLASS_STATUS[c.status] ?? c.status}</span>
                  <Link className="jb" href={`/trainer/kelas/${c.code}`}>
                    Buka
                  </Link>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <EmptyState text="Belum ada kelas yang ditugaskan kepada Anda." />
        )}
      </PortalCard>
    </>
  );
}
