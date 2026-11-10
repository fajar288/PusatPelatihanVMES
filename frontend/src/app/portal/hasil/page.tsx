import Link from "next/link";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { ResultsInfo } from "@/lib/lms";
import type { EnrollmentInfo } from "@/lib/portal-types";

export const instant = false;

/** /portal/hasil — Results: nilai kuis (percobaan terbaik), nilai & umpan balik tugas, nilai akhir kelas. */
export default async function PortalResultsPage() {
  const { token } = await requireUser("/portal/hasil");
  const [results, enrollments] = await Promise.all([
    api<{ data: ResultsInfo }>("/me/learning/results", { token }).then((r) => r.data),
    api<{ data: EnrollmentInfo[] }>("/me/enrollments", { token }).then((r) => r.data),
  ]);
  const empty = results.quizzes.length === 0 && results.assignments.length === 0;

  return (
    <>
      <PortalCard title="Hasil Kuis & Tes">
        {results.quizzes.length === 0 ? (
          <p className="fh">Belum ada kuis.</p>
        ) : (
          <table className="att" style={{ marginTop: 12 }}>
            <thead>
              <tr>
                <th>Kuis</th>
                <th>Course</th>
                <th>Percobaan</th>
                <th>Nilai terbaik</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {results.quizzes.map((q) => (
                <tr key={q.lessonId}>
                  <td>
                    <Link href={`/portal/belajar/${q.course.slug}/${q.lessonId}`}>
                      <b>{q.title}</b>
                    </Link>
                    <small>{q.kindLabel} • lulus ≥ {q.passingScore}%</small>
                  </td>
                  <td>{q.course.title}</td>
                  <td>{q.attempts}</td>
                  <td>{q.best ? `${q.best.percent}% (${q.best.score}/${q.best.maxScore})` : "-"}</td>
                  <td>{q.best ? <span className={"jst " + (q.best.passed ? "ok" : "no")}>{q.best.passed ? "Lulus" : "Belum lulus"}</span> : <span className="jst wn">Belum</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </PortalCard>

      <PortalCard title="Hasil Tugas">
        {results.assignments.length === 0 ? (
          <p className="fh">Belum ada tugas.</p>
        ) : (
          <table className="att" style={{ marginTop: 12 }}>
            <thead>
              <tr>
                <th>Tugas</th>
                <th>Course</th>
                <th>Status</th>
                <th>Nilai</th>
                <th>Umpan balik</th>
              </tr>
            </thead>
            <tbody>
              {results.assignments.map((a) => (
                <tr key={a.lessonId}>
                  <td>
                    <Link href={`/portal/belajar/${a.course.slug}/${a.lessonId}`}>
                      <b>{a.title}</b>
                    </Link>
                    {a.submission && <small>{a.submission.submittedLabel}</small>}
                  </td>
                  <td>{a.course.title}</td>
                  <td>{a.submission ? <span className={"jst " + (a.submission.status === "graded" ? "ok" : a.submission.status === "returned" ? "no" : "wn")}>{a.submission.statusLabel}</span> : <span className="jst wn">Belum</span>}</td>
                  <td>{a.submission?.score != null ? `${a.submission.score}/${a.maxScore}` : "-"}</td>
                  <td>{a.submission?.feedback ?? "-"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </PortalCard>

      {enrollments.some((e) => e.finalScore != null || e.status === "completed") && (
        <PortalCard title="Nilai Akhir Kelas">
          <ul className="sp">
            {enrollments.map((e) => (
              <li key={e.id}>
                <span>
                  {e.schedule?.title} • {e.schedule?.code}
                </span>
                <b>{e.finalScore ?? "-"}</b>
              </li>
            ))}
          </ul>
        </PortalCard>
      )}
      {empty && <EmptyState text="Hasil akan muncul setelah Anda mengerjakan kuis atau tugas." href="/portal/belajar" cta="Ke halaman Belajar" />}
    </>
  );
}
