import Link from "next/link";
import { ProgressBar } from "@/components/lms/ui";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { CourseCard, CourseDetail, ResultsInfo } from "@/lib/lms";
import type { EnrollmentInfo } from "@/lib/portal-types";

export const instant = false;

/** /portal/progres — My Progress: kehadiran per kelas, progres per course & modul, ringkasan nilai kuis/tugas. */
export default async function PortalProgressPage() {
  const { token } = await requireUser("/portal/progres");
  const [enrollments, courses, results] = await Promise.all([
    api<{ data: EnrollmentInfo[] }>("/me/enrollments", { token }).then((r) => r.data),
    api<{ data: CourseCard[] }>("/me/learning", { token }).then((r) => r.data),
    api<{ data: ResultsInfo }>("/me/learning/results", { token }).then((r) => r.data),
  ]);
  const details = await Promise.all(
    courses.filter((c) => c.access).map((c) => api<{ data: CourseDetail }>(`/me/learning/${c.slug}`, { token }).then((r) => r.data).catch(() => null)),
  );
  const lessonsDone = courses.reduce((n, c) => n + c.completed, 0);
  const lessonsTotal = courses.reduce((n, c) => n + c.lessonsCount, 0);
  const quizBest = results.quizzes.filter((q) => q.best).map((q) => q.best!.percent ?? 0);
  const graded = results.assignments.filter((a) => a.submission?.status === "graded");
  const att = enrollments.map((e) => e.attendancePercent).filter((v): v is number => v !== null);

  return (
    <>
      <div className="kic pstats">
        <div className="kic1">
          <small>Kehadiran rata-rata</small>
          <b>{att.length ? `${Math.round(att.reduce((a, b) => a + b, 0) / att.length)}%` : "-"}</b>
        </div>
        <div className="kic1">
          <small>Materi selesai</small>
          <b>
            {lessonsDone}/{lessonsTotal}
          </b>
        </div>
        <div className="kic1">
          <small>Rata-rata kuis</small>
          <b>{quizBest.length ? `${Math.round(quizBest.reduce((a, b) => a + b, 0) / quizBest.length)}%` : "-"}</b>
        </div>
        <div className="kic1">
          <small>Tugas dinilai</small>
          <b>
            {graded.length}/{results.assignments.length}
          </b>
        </div>
      </div>

      <PortalCard title="Progres Saya">
        {courses.length === 0 && <EmptyState text="Belum ada course yang bisa dipantau." href="/portal/belajar" cta="Ke halaman Belajar" />}
        {courses.map((c, i) => {
          const d = details[courses.filter((x) => x.access).indexOf(c)] ?? null;
          const enr = enrollments.find((e) => e.id === c.enrollmentId);
          return (
            <div className="kbx" key={c.slug} style={{ marginTop: i === 0 ? 16 : 18, padding: 22 }}>
              <div className="pcard-h">
                <h3 style={{ fontSize: 18 }}>
                  <Link href={`/portal/belajar/${c.slug}`}>{c.title}</Link>
                </h3>
                <span className={"jst " + (c.percent >= 100 ? "ok" : c.percent > 0 ? "wn" : "no")}>{c.percent}%</span>
              </div>
              <p className="fh" style={{ marginTop: 4 }}>
                {c.class.title} • {c.class.code}
                {enr ? ` • kehadiran ${enr.attendancePercent === null ? "-" : `${enr.attendancePercent}%`}` : ""}
                {enr?.finalScore != null ? ` • nilai akhir ${enr.finalScore}` : ""}
              </p>
              <ProgressBar percent={c.percent} />
              {d && (
                <ul className="sp" style={{ marginBottom: 0 }}>
                  {d.modules.map((m) => (
                    <li key={m.id}>
                      <span>{m.title}</span>
                      <b>
                        <span className={"jst " + (m.isCompleted ? "ok" : m.completed > 0 ? "wn" : "no")}>
                          {m.completed}/{m.lessonsCount}
                        </span>
                      </b>
                    </li>
                  ))}
                </ul>
              )}
              {!c.access && <p className="fh">{c.accessReason}</p>}
            </div>
          );
        })}
      </PortalCard>

      {(results.quizzes.length > 0 || results.assignments.length > 0) && (
        <PortalCard
          title="Nilai"
          action={
            <Link className="lk" href="/portal/hasil">
              Rincian hasil
            </Link>
          }
        >
          <ul className="sp">
            {results.quizzes.map((q) => (
              <li key={`q${q.lessonId}`}>
                <span>
                  {q.kindLabel}: {q.title}
                </span>
                <b>{q.best ? <span className={"jst " + (q.best.passed ? "ok" : "no")}>{q.best.percent}%</span> : <span className="fh">belum dikerjakan</span>}</b>
              </li>
            ))}
            {results.assignments.map((a) => (
              <li key={`a${a.lessonId}`}>
                <span>Tugas: {a.title}</span>
                <b>{a.submission?.status === "graded" ? `${a.submission.score}/${a.maxScore}` : <span className="fh">{a.submission ? a.submission.statusLabel : "belum dikumpulkan"}</span>}</b>
              </li>
            ))}
          </ul>
        </PortalCard>
      )}
    </>
  );
}
