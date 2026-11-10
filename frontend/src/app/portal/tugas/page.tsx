import Link from "next/link";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import { type AssignmentListItem, SUBMISSION_PILL } from "@/lib/lms";

export const instant = false;

/** /portal/tugas — Assignments: semua tugas lintas course dengan batas waktu & status pengumpulan. */
export default async function PortalAssignmentsPage() {
  const { token } = await requireUser("/portal/tugas");
  const items = (await api<{ data: AssignmentListItem[] }>("/me/learning/assignments", { token })).data;
  const todo = items.filter((a) => !a.submission || a.submission.status === "returned");
  const done = items.filter((a) => a.submission && a.submission.status !== "returned");

  const row = (a: AssignmentListItem) => (
    <div className="jr prow" key={a.lessonId}>
      <div className="j1">
        <Link href={`/portal/belajar/${a.course.slug}/${a.lessonId}`}>
          <h3>{a.title}</h3>
        </Link>
        <p>
          {a.course.title} • {a.module} • maks. {a.maxScore}
        </p>
      </div>
      <div className="j2">
        <b>{a.dueLabel ? `Tenggat ${a.dueLabel}` : "Tanpa tenggat"}</b>
        <span>{a.submission ? `Dikumpulkan ${a.submission.submittedLabel}` : a.overdue ? "Terlambat" : "Belum dikumpulkan"}</span>
      </div>
      <div className="j3">
        {a.submission ? <span className={"jst " + SUBMISSION_PILL[a.submission.status]}>{a.submission.statusLabel}</span> : <span className={"jst " + (a.overdue ? "no" : "wn")}>{a.overdue ? "Lewat tenggat" : "Belum"}</span>}
        <b>{a.submission?.score != null ? `${a.submission.score}/${a.maxScore}` : "-"}</b>
      </div>
      <Link className="jb" href={`/portal/belajar/${a.course.slug}/${a.lessonId}`}>
        {a.submission && a.submission.status !== "returned" ? "Lihat" : "Kerjakan"}
      </Link>
    </div>
  );

  return (
    <>
      <PortalCard title="Tugas yang harus dikerjakan">
        {todo.length ? <div className="kjl" style={{ marginTop: 16 }}>{todo.map(row)}</div> : <EmptyState text={items.length ? "Semua tugas sudah dikumpulkan." : "Belum ada tugas."} href="/portal/belajar" cta="Ke halaman Belajar" />}
      </PortalCard>
      {done.length > 0 && (
        <PortalCard title="Sudah dikumpulkan">
          <div className="kjl" style={{ marginTop: 16 }}>{done.map(row)}</div>
        </PortalCard>
      )}
    </>
  );
}
