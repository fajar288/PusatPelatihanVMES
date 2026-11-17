"use client";

import Link from "next/link";
import { useActionState } from "react";
import { FieldError, FormMessage } from "@/components/auth/AuthShell";
import type { FormState } from "@/lib/actions/auth";
import { submitSurveyAction } from "@/lib/actions/survey";
import { SCALE_LABELS, type SurveyQuestion } from "@/lib/surveys";

/** Formulir survei evaluasi: skala 1-5 (radio) dan komentar. Setelah terkirim, formulir diganti ucapan terima kasih. */
export default function SurveyForm({ id, questions }: { id: number; questions: SurveyQuestion[] }) {
  const [state, action, pending] = useActionState<FormState, FormData>(submitSurveyAction, { ok: false });
  if (state.ok) {
    return (
      <div style={{ marginTop: 16 }}>
        <FormMessage ok message={state.message} />
        <p style={{ marginTop: 12 }}>
          <Link className="lk" href="/portal/survei">
            Kembali ke daftar survei
          </Link>
        </p>
      </div>
    );
  }

  return (
    <form className="ff" action={action} style={{ marginTop: 16 }}>
      <input type="hidden" name="id" value={id} />
      {questions.map((q, i) =>
        q.type === "scale" ? (
          <fieldset key={q.key} className="m-0! border-0 p-0!">
            <legend className="mb-2! font-bold text-[var(--ink)]">
              {i + 1}. {q.label}
              {q.required && <span aria-hidden="true"> *</span>}
            </legend>
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={q.label}>
              {[1, 2, 3, 4, 5].map((v) => (
                <label
                  key={v}
                  className="mb-0! flex! cursor-pointer items-center gap-1.5 rounded-full border border-[var(--ln)] bg-white px-3! py-1.5! text-[13px] font-semibold! has-checked:border-[var(--p)] has-checked:bg-[var(--bg)]"
                >
                  <input type="radio" name={`q_${q.key}`} value={v} required={q.required} className="m-0! size-4! shrink-0 rounded-none! border-0! p-0! accent-[var(--p)] shadow-none!" />
                  <b>{v}</b>
                  <span className="text-[var(--mut)]">{SCALE_LABELS[v]}</span>
                </label>
              ))}
            </div>
            <FieldError errors={state.errors} name={`q_${q.key}`} />
          </fieldset>
        ) : (
          <div key={q.key}>
            <label htmlFor={`sv-${q.key}`}>
              {i + 1}. {q.label}
              {q.required ? " *" : " (opsional)"}
            </label>
            <textarea id={`sv-${q.key}`} name={`q_${q.key}`} rows={3} maxLength={2000} required={q.required} />
            <FieldError errors={state.errors} name={`q_${q.key}`} />
          </div>
        ),
      )}
      <p className="fh" style={{ fontSize: 13 }}>
        Jangan menulis nomor telepon, email, atau data pribadi lain di komentar. Komentar dibaca tim PPTI dan dapat dianalisis otomatis tanpa nama Anda.
      </p>
      <FormMessage ok={false} message={state.message} />
      <button className="btn" type="submit" disabled={pending}>
        {pending ? "Mengirim..." : "Kirim survei"}
      </button>
    </form>
  );
}
