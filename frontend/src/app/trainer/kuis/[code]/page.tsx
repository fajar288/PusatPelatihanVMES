import { notFound } from "next/navigation";
import { BackLink, MetaList, PortalCard } from "@/components/portal/ui";
import { api, ApiRequestError } from "@/lib/auth";
import type { TrainerQuizResults } from "@/lib/lms";
import { requireTrainer } from "@/lib/trainer";

export const instant = false;

/** /trainer/kuis/[code] — matriks hasil kuis: peserta × kuis (nilai terbaik, lulus, jumlah percobaan). */
export default async function TrainerClassQuizResults({ params }: PageProps<"/trainer/kuis/[code]">) {
  const { code } = await params;
  const { token } = await requireTrainer(`/trainer/kuis/${code}`);
  let d: TrainerQuizResults;
  try {
    d = (await api<{ data: TrainerQuizResults }>(`/trainer/classes/${encodeURIComponent(code)}/quiz-results`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    throw e;
  }
  const avg = (lessonId: number) => {
    const v = d.participants.map((p) => p.results[String(lessonId)]?.percent).filter((x): x is number => x != null);
    return v.length ? Math.round(v.reduce((a, b) => a + b, 0) / v.length) : null;
  };

  return (
    <>
      <BackLink href="/trainer/kuis">Kembali ke Hasil kuis</BackLink>
      <PortalCard title={`Hasil kuis — ${d.class.title}`}>
        {d.quizzes.length === 0 ? (
          <p className="fh">Belum ada kuis pada course kelas ini.</p>
        ) : (
          <>
            <ul className="sp">
              {d.quizzes.map((q) => (
                <li key={q.lessonId}>
                  <span>
                    <b style={{ color: "var(--ink)" }}>
                      {q.kindLabel}: {q.title}
                    </b>
                    <MetaList
                      compact
                      style={{ marginTop: 2 }}
                      items={[
                        { icon: "grad", value: q.course },
                        { value: `${q.questionsCount} soal` },
                        { label: "Lulus ≥", value: `${q.passingScore}%` },
                      ]}
                    />
                  </span>
                  <b className="spv">
                    Rata-rata {avg(q.lessonId) === null ? "-" : `${avg(q.lessonId)}%`}
                    <small>
                      {d.participants.filter((p) => p.results[String(q.lessonId)]).length}/{d.participants.length} mengerjakan
                    </small>
                  </b>
                </li>
              ))}
            </ul>
            <div style={{ overflowX: "auto" }}>
              <table className="att lqm">
                <thead>
                  <tr>
                    <th>Peserta</th>
                    {d.quizzes.map((q) => (
                      <th key={q.lessonId}>{q.title}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {d.participants.map((p) => (
                    <tr key={p.id}>
                      <td>
                        <b>{p.name}</b>
                      </td>
                      {d.quizzes.map((q) => {
                        const r = p.results[String(q.lessonId)];
                        return (
                          <td key={q.lessonId}>
                            {r ? (
                              <>
                                <span className={"jst " + (r.passed ? "ok" : "no")}>{r.percent}%</span>
                                <small>
                                  Skor {r.score}/{r.maxScore}, {r.attempts}× percobaan
                                </small>
                              </>
                            ) : (
                              <span className="fh">belum</span>
                            )}
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </PortalCard>
    </>
  );
}
