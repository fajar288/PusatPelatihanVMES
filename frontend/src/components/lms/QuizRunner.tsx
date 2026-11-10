"use client";

import { useEffect, useState, useTransition } from "react";
import { startQuizAction, submitQuizAction } from "@/lib/actions/lms";
import type { AttemptInfo, QuizState } from "@/lib/lms";

/** Pengerjaan kuis/pre-test/post-test: mulai → jawab (timer opsional) → submit → hasil & pembahasan. */
export default function QuizRunner({ course, lesson, initial }: { course: string; lesson: number; initial: QuizState }) {
  const [quiz, setQuiz] = useState<QuizState>(initial);
  const [answers, setAnswers] = useState<Record<string, string | string[]>>(initial.open?.answers ?? {});
  const [result, setResult] = useState<AttemptInfo | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [pending, start] = useTransition();
  const [now, setNow] = useState(() => Date.now());
  const deadline = quiz.open?.deadline ?? null;
  const left = deadline ? Math.max(0, Math.floor((new Date(deadline).getTime() - now) / 1000)) : null;

  // timer: detak tiap detik selama ada batas waktu
  useEffect(() => {
    if (!deadline) return;
    const id = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(id);
  }, [deadline]);

  const submit = () =>
    start(async () => {
      const r = await submitQuizAction(course, lesson, answers);
      if (!r.ok) return setErr(r.message);
      setQuiz(r.data);
      setResult(r.data.result ?? null);
      setErr(null);
    });

  useEffect(() => {
    if (left === 0 && quiz.open) submit();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [left]);

  const begin = () =>
    start(async () => {
      const r = await startQuizAction(course, lesson);
      if (!r.ok) return setErr(r.message);
      setQuiz(r.data);
      setAnswers(r.data.open?.answers ?? {});
      setResult(null);
      setErr(null);
    });

  const setOne = (id: number, v: string) => setAnswers((a) => ({ ...a, [id]: v }));
  const toggle = (id: number, key: string) =>
    setAnswers((a) => {
      const cur = Array.isArray(a[id]) ? (a[id] as string[]) : [];
      return { ...a, [id]: cur.includes(key) ? cur.filter((k) => k !== key) : [...cur, key] };
    });

  const info = (
    <ul className="sp" style={{ marginTop: 0 }}>
      <li>
        <span>Jenis</span>
        <b>{quiz.kindLabel}</b>
      </li>
      <li>
        <span>Jumlah soal</span>
        <b>{quiz.questionsCount}</b>
      </li>
      <li>
        <span>Nilai lulus</span>
        <b>{quiz.passingScore}%</b>
      </li>
      <li>
        <span>Batas waktu</span>
        <b>{quiz.timeLimitMinutes ? `${quiz.timeLimitMinutes} menit` : "Tidak ada"}</b>
      </li>
      <li>
        <span>Percobaan</span>
        <b>
          {quiz.attemptsUsed}
          {quiz.maxAttempts ? ` dari ${quiz.maxAttempts}` : " (tak terbatas)"}
        </b>
      </li>
    </ul>
  );

  if (quiz.open) {
    const o = quiz.open;
    return (
      <div className="lquiz">
        <div className="pcard-h">
          <b>
            Percobaan ke-{o.attemptNo} • {o.questions.length} soal
          </b>
          {left !== null && (
            <span className={"jst " + (left < 60 ? "no" : "wn")}>
              Sisa {Math.floor(left / 60)}:{String(left % 60).padStart(2, "0")}
            </span>
          )}
        </div>
        <ol className="lq">
          {o.questions.map((q) => (
            <li key={q.id}>
              <p>
                {q.question} <small className="fh">({q.points} poin)</small>
              </p>
              {q.type === "short" ? (
                <input className="lq-in" value={(answers[q.id] as string) ?? ""} onChange={(e) => setOne(q.id, e.target.value)} placeholder="Jawaban singkat" />
              ) : (
                <div className="lq-opts">
                  {q.options.map((op) => (
                    <label key={op.key}>
                      {q.type === "multiple" ? (
                        <input type="checkbox" checked={Array.isArray(answers[q.id]) && (answers[q.id] as string[]).includes(op.key)} onChange={() => toggle(q.id, op.key)} />
                      ) : (
                        <input type="radio" name={`q${q.id}`} checked={answers[q.id] === op.key} onChange={() => setOne(q.id, op.key)} />
                      )}
                      <span>
                        {q.type !== "true_false" && <b>{op.key.toUpperCase()}.</b>} {op.text}
                      </span>
                    </label>
                  ))}
                </div>
              )}
            </li>
          ))}
        </ol>
        {err && <p className="fh" style={{ color: "#b34200" }}>{err}</p>}
        <button type="button" className="kb1" disabled={pending} onClick={submit}>
          {pending ? "Mengirim…" : "Kumpulkan jawaban"}
        </button>
      </div>
    );
  }

  return (
    <div className="lquiz">
      {result && (
        <div className={"lres " + (result.passed ? "ok" : "no")}>
          <b>
            {result.passed ? "Lulus" : "Belum lulus"} • {result.percent}%
          </b>
          <span>
            Skor {result.score} dari {result.maxScore} • percobaan ke-{result.attemptNo}
          </span>
        </div>
      )}
      {result?.review && (
        <ol className="lq lq-review">
          {result.review.map((r, i) => (
            <li key={r.id} className={r.correct ? "ok" : "no"}>
              <p>
                {i + 1}. {r.question} <span className={"jst " + (r.correct ? "ok" : "no")}>{r.correct ? `+${r.points}` : "0"}</span>
              </p>
              <small>
                Jawaban Anda: <b>{fmt(r.given, r.options)}</b>
                {!r.correct && r.answer && (
                  <>
                    {" "}
                    • Kunci: <b>{fmt(r.answer, r.options)}</b>
                  </>
                )}
              </small>
              {r.explanation && <small className="fh">{r.explanation}</small>}
            </li>
          ))}
        </ol>
      )}
      {!result && info}
      {quiz.best && !result && (
        <p className="fh" style={{ marginTop: 10 }}>
          Nilai terbaik: <b>{quiz.best.percent}%</b> ({quiz.best.passed ? "lulus" : "belum lulus"}) • {quiz.best.submittedLabel}
        </p>
      )}
      {err && <p className="fh" style={{ color: "#b34200", marginTop: 8 }}>{err}</p>}
      {quiz.canStart && (
        <button type="button" className="kb1" disabled={pending} onClick={begin} style={{ marginTop: 14 }}>
          {pending ? "Memuat…" : quiz.attemptsUsed > 0 ? "Coba lagi" : "Mulai kuis"}
        </button>
      )}
      {!quiz.canStart && !quiz.open && (
        <p className="fh" style={{ marginTop: 10 }}>
          {quiz.questionsCount === 0 ? "Kuis belum memiliki soal." : "Batas percobaan sudah tercapai."}
        </p>
      )}
    </div>
  );
}

function fmt(v: string | string[] | null, options: { key: string; text: string }[]): string {
  if (v === null || v === undefined || v === "") return "-";
  const arr = Array.isArray(v) ? v : [v];
  return arr.map((k) => options.find((o) => o.key === String(k).toLowerCase())?.text ?? String(k)).join(", ");
}
