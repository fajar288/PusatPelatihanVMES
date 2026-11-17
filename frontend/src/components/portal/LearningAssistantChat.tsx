"use client";

import Link from "next/link";
import { useEffect, useRef, useState, useTransition } from "react";
import AiFeedback from "@/components/portal/AiFeedback";
import Icon from "@/components/ui/Icon";
import { askAssistantAction } from "@/lib/actions/assistant";
import type { AssistantCourse, AssistantMessage, AssistantStatus } from "@/lib/portal-types";

const MAX = 1000;

/** Tautan forum diskusi: forum lesson bila pertanyaan diajukan dari satu lesson, selain itu forum course. */
const forumHref = (m: AssistantMessage) => (m.lessonId ? `/portal/belajar/${m.course}/${m.lessonId}#forum` : `/portal/belajar/${m.course}#forum`);

function Answer({ m }: { m: AssistantMessage }) {
  return (
    <div className="min-w-0 rounded-xl rounded-tl-sm border border-[var(--ln)] bg-white px-3.5! py-3! text-sm text-[var(--ink)]">
      <p className="m-0! leading-relaxed break-words whitespace-pre-line">
        {m.segments.length > 0
          ? m.segments.map((s, i) => (
              <span key={i}>
                {s.text}
                {s.refs.map((n) => (
                  <sup key={n} className="ml-0.5! text-[11px] font-bold">
                    <a href={`#src-${m.id}-${n}`} className="text-[var(--p)] no-underline" aria-label={`Sumber ${n}`}>
                      [{n}]
                    </a>
                  </sup>
                ))}
              </span>
            ))
          : m.answer}
      </p>

      {m.sources.length > 0 && (
        <div className="mt-3! border-t border-[var(--ln)] pt-2.5!">
          <b className="block text-xs text-[var(--mut)]">Sumber dari materi kelas</b>
          <ol className="m-0! mt-1.5! list-none p-0!">
            {m.sources.map((s) => (
              <li key={s.n} id={`src-${m.id}-${s.n}`} className="mt-1.5! text-[13px] break-words">
                <span className="font-bold text-[var(--p)]">[{s.n}]</span>{" "}
                <Link href={`/portal/belajar/${m.course}/${s.lessonId}`} className="font-bold">
                  {s.title}
                </Link>
                {s.module && <span className="text-[var(--mut)]"> ({s.module})</span>}
                {s.quotes.map((q, i) => (
                  <q key={i} className="mt-0.5! block text-xs text-[var(--mut)] italic">
                    {q}
                  </q>
                ))}
              </li>
            ))}
          </ol>
        </div>
      )}

      {!m.resolved && (
        <p className="m-0! mt-2.5! rounded-lg bg-[var(--bg)] px-3! py-2! text-[13px]">
          <Link href={forumHref(m)} className="font-bold">
            Tanyakan ke trainer di forum diskusi
          </Link>
        </p>
      )}

      <div className="mt-2.5! flex flex-wrap items-center gap-x-2 gap-y-1">
        <span className="rounded-full border border-[var(--ln)] px-2! py-0.5! text-[11px] font-bold text-[var(--mut)]">Dibuat AI</span>
        <AiFeedback insightId={m.id} initial={m.feedback} />
      </div>
    </div>
  );
}

/**
 * Asisten Belajar: tanya jawab berdasarkan materi lesson yang terbit pada course peserta, dengan sumber bernomor.
 * Semua aturan (akses course, kuota, status fitur) diperiksa di server; komponen ini hanya menampilkan hasilnya.
 */
export default function LearningAssistantChat({
  status,
  history,
  initialCourse,
  initialLesson,
}: {
  status: AssistantStatus;
  history: AssistantMessage[];
  initialCourse?: string;
  initialLesson?: number;
}) {
  const courses: AssistantCourse[] = status.courses ?? [];
  const startCourse = courses.find((c) => c.slug === initialCourse) ?? courses[0];
  const [course, setCourse] = useState(startCourse?.slug ?? "");
  const [lesson, setLesson] = useState<number | null>(startCourse?.lessons.some((l) => l.id === initialLesson) ? (initialLesson ?? null) : null);
  const [question, setQuestion] = useState("");
  const [messages, setMessages] = useState<AssistantMessage[]>(history);
  const [remaining, setRemaining] = useState(status.remaining ?? 0);
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (messages.length > history.length) endRef.current?.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }, [messages.length, history.length]);

  const current = courses.find((c) => c.slug === course);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (pending) return;
    start(async () => {
      setError(null);
      const r = await askAssistantAction(course, question, lesson);
      if (r.ok) {
        setMessages((ms) => [...ms, r.message]);
        setQuestion("");
        setRemaining((n) => Math.max(0, n - 1));
      } else {
        setError(r.message);
        if (r.code === "ai_limit") setRemaining(0);
      }
    });
  };

  return (
    <div className="mt-3.5! overflow-hidden rounded-xl border border-[var(--ln)] bg-white">
      <div className="flex items-center gap-3 border-b border-[var(--ln)] bg-[var(--bg)] px-4! py-3!">
        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[var(--p)] text-white [&_svg]:h-[18px]! [&_svg]:w-[18px]!" aria-hidden="true">
          <Icon name="bot" />
        </span>
        <div className="min-w-0 flex-1!">
          <b className="block text-[15px] text-[var(--ink)]">Asisten Belajar</b>
          <small className="block text-[13px] text-[var(--mut)]">
            Sisa {remaining} dari {status.limit ?? 0} pertanyaan hari ini
          </small>
        </div>
        <span className="shrink-0 rounded-full border border-[var(--p)] px-2.5! py-0.5! text-xs font-bold whitespace-nowrap text-[var(--p)]">{status.label}</span>
      </div>

      <div className="max-h-[60vh] overflow-y-auto px-3! py-4! sm:px-4!" aria-live="polite">
        {messages.length === 0 ? (
          <p className="m-0! py-4! text-center text-sm text-[var(--mut)]">{status.message}</p>
        ) : (
          <ul className="m-0! flex list-none flex-col gap-4 p-0!">
            {messages.map((m) => (
              <li key={m.id} className="flex flex-col gap-2">
                <p className="m-0! max-w-[85%] self-end rounded-xl rounded-tr-sm bg-[var(--p)] px-3.5! py-2.5! text-sm break-words whitespace-pre-line text-white">{m.question}</p>
                <div className="max-w-full sm:max-w-[92%]">
                  <Answer m={m} />
                </div>
              </li>
            ))}
          </ul>
        )}
        {pending && (
          <p className="m-0! mt-3! text-sm text-[var(--mut)]" role="status">
            Asisten sedang mencari jawaban di materi...
          </p>
        )}
        <div ref={endRef} />
      </div>

      {courses.length === 0 ? (
        <p className="m-0! border-t border-[var(--ln)] px-4! py-3! text-sm text-[var(--mut)]">
          Belum ada materi kelas yang bisa ditanyakan. Asisten Belajar tersedia selama Anda punya akses ke materi di menu <Link href="/portal/belajar">Belajar</Link>.
        </p>
      ) : (
        <form onSubmit={submit} className="flex flex-col gap-2 border-t border-[var(--ln)] p-3!" aria-label="Kirim pertanyaan ke Asisten Belajar">
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <label className="flex min-w-0 flex-col gap-1 text-xs font-bold text-[var(--mut)]">
              Course
              <select
                value={course}
                onChange={(e) => {
                  setCourse(e.target.value);
                  setLesson(null);
                }}
                className="min-w-0 rounded-lg border border-[var(--ln)] bg-white px-2.5! py-2! text-sm font-normal text-[var(--ink)]"
              >
                {courses.map((c) => (
                  <option key={c.slug} value={c.slug}>
                    {c.title}
                    {c.classCode ? ` (${c.classCode})` : ""}
                  </option>
                ))}
              </select>
            </label>
            <label className="flex min-w-0 flex-col gap-1 text-xs font-bold text-[var(--mut)]">
              Fokus materi
              <select
                value={lesson ?? ""}
                onChange={(e) => setLesson(e.target.value ? Number(e.target.value) : null)}
                className="min-w-0 rounded-lg border border-[var(--ln)] bg-white px-2.5! py-2! text-sm font-normal text-[var(--ink)]"
              >
                <option value="">Semua materi course</option>
                {current?.lessons.map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.title}
                  </option>
                ))}
              </select>
            </label>
          </div>
          <label htmlFor="assistant-q" className="sr-only">
            Pertanyaan
          </label>
          <textarea
            id="assistant-q"
            value={question}
            onChange={(e) => setQuestion(e.target.value.slice(0, MAX))}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.shiftKey && !e.nativeEvent.isComposing) {
                e.preventDefault();
                e.currentTarget.form?.requestSubmit();
              }
            }}
            rows={2}
            disabled={remaining <= 0}
            placeholder={remaining > 0 ? "Tulis pertanyaan tentang materi kelas..." : "Kuota pertanyaan hari ini sudah habis"}
            className="min-w-0 resize-y rounded-lg border border-[var(--ln)] px-3! py-2! text-sm text-[var(--ink)] disabled:cursor-not-allowed disabled:bg-[var(--bg)]"
          />
          <div className="flex flex-wrap items-center justify-between gap-2">
            <small className="text-xs text-[var(--mut)]">
              Jangan menulis data pribadi. {question.length}/{MAX}
            </small>
            <button
              type="submit"
              disabled={pending || remaining <= 0 || question.trim().length < 3}
              className="cursor-pointer rounded-lg bg-[var(--p)] px-4! py-2! text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"
            >
              {pending ? "Mengirim..." : "Kirim"}
            </button>
          </div>
          {error && (
            <p className="m-0! text-[13px] text-[var(--o)]" role="alert">
              {error}
            </p>
          )}
        </form>
      )}
    </div>
  );
}
