"use client";

import { useState, useTransition } from "react";
import { aiFeedbackAction } from "@/lib/actions/ai";

/** Tombol umpan balik untuk keluaran AI: "Membantu" / "Tidak membantu". Pilihan bisa diubah; dipakai sebagai metrik kualitas. */
export default function AiFeedback({ insightId, initial }: { insightId: number; initial: boolean | null }) {
  const [value, setValue] = useState<boolean | null>(initial);
  const [error, setError] = useState<string | null>(null);
  const [pending, start] = useTransition();

  const send = (helpful: boolean) =>
    start(async () => {
      setError(null);
      const r = await aiFeedbackAction(insightId, helpful);
      if (r.ok) setValue(r.helpful);
      else setError(r.message);
    });

  const btn = (helpful: boolean, label: string) => (
    <button
      type="button"
      onClick={() => send(helpful)}
      disabled={pending}
      aria-pressed={value === helpful}
      className={
        "cursor-pointer rounded-full border px-2! py-0.5! text-[11px] font-bold whitespace-nowrap disabled:opacity-60 " +
        (value === helpful ? "border-[var(--p)] bg-[var(--p)] text-white" : "border-[var(--ln)] bg-white text-[var(--mut)] hover:border-[var(--p)]")
      }
    >
      {label}
    </button>
  );

  return (
    <span className="mt-1! flex flex-wrap items-center gap-1" aria-live="polite">
      {btn(true, "Membantu")}
      {btn(false, "Tidak membantu")}
      {error && <small className="text-[11px] text-[var(--o)]">{error}</small>}
    </span>
  );
}
