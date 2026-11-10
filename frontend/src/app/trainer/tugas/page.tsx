import Link from "next/link";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api } from "@/lib/auth";
import type { TrainerSubmissions } from "@/lib/lms";
import { requireTrainer, type TrainerClass } from "@/lib/trainer";

export const instant = false;

/** /trainer/tugas — Assignments: per kelas, jumlah pengumpulan yang menunggu penilaian. */
export default async function TrainerAssignmentsPage() {
  const { token } = await requireTrainer("/trainer/tugas");
  const classes = (await api<{ data: TrainerClass[] }>("/trainer/classes", { token })).data;
  const stats = await Promise.all(
    classes.map((c) =>
      api<{ data: TrainerSubmissions }>(`/trainer/classes/${encodeURIComponent(c.code)}/submissions`, { token })
        .then((r) => ({ pending: r.data.submissions.filter((s) => s.status === "submitted").length, total: r.data.submissions.length, tasks: r.data.assignments.length }))
        .catch(() => ({ pending: 0, total: 0, tasks: 0 })),
    ),
  );
  return (
    <PortalCard title="Penilaian Tugas">
      {classes.length ? (
        <div className="kjl" style={{ marginTop: 16 }}>
          {classes.map((c, i) => (
            <div className="kj" key={c.code}>
              <div>
                <b>{c.title}</b>
                <p>
                  {c.code} • {c.dateLabel} • {stats[i].tasks} tugas • {stats[i].total} pengumpulan
                </p>
              </div>
              <div className="kjr">
                <span className={"jst " + (stats[i].pending > 0 ? "wn" : "ok")}>{stats[i].pending > 0 ? `${stats[i].pending} menunggu` : "Tidak ada antrean"}</span>
                <Link className="jb" href={`/trainer/tugas/${c.code}`}>
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
  );
}
