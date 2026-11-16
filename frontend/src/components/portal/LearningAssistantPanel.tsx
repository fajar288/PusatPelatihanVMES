import Icon from "@/components/ui/Icon";
import type { AssistantStatus } from "@/lib/portal-types";

/**
 * Panel Asisten Belajar (kerangka Roadmap 5 tahap 5G). Sengaja tanpa "use client", tanpa form action, dan tanpa
 * fetch dari browser: input & tombol nonaktif, sehingga tidak ada pertanyaan atau data peserta yang terkirim ke mana pun.
 * Asisten sungguhan (jawaban dari materi lesson yang disetujui + sitasi) menyusul di tahap 5I.5.
 */
export default function LearningAssistantPanel({ status }: { status: AssistantStatus }) {
  return (
    <div className="mt-3.5! overflow-hidden rounded-xl border border-[var(--ln)] bg-white" aria-disabled="true">
      <div className="flex items-center gap-3 border-b border-[var(--ln)] bg-[var(--bg)] px-4! py-3!">
        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[var(--p)] text-white [&_svg]:h-[18px]! [&_svg]:w-[18px]!" aria-hidden="true">
          <Icon name="bot" />
        </span>
        <div className="min-w-0 flex-1!">
          <b className="block text-[15px] text-[var(--ink)]">Asisten Belajar</b>
          <small className="block text-[13px] text-[var(--mut)]">Tanya jawab seputar materi kelas Anda</small>
        </div>
        <span className="shrink-0 rounded-full border border-[var(--o)] px-2.5! py-0.5! text-xs font-bold whitespace-nowrap text-[var(--o)]">{status.label}</span>
      </div>

      <div className="px-4! py-8! text-center" role="status">
        <div className="mx-auto! max-w-md">
          <p className="m-0! font-bold text-[var(--ink)]">Fitur ini belum tersedia</p>
          <p className="mt-1.5! mb-0! text-sm text-[var(--mut)]">{status.message}</p>
        </div>
      </div>

      <form className="flex gap-2 border-t border-[var(--ln)] p-3!" aria-label="Kirim pertanyaan ke Asisten Belajar">
        <label htmlFor="assistant-q" className="sr-only">
          Pertanyaan
        </label>
        <input
          id="assistant-q"
          type="text"
          disabled
          placeholder="Asisten Belajar belum tersedia"
          className="min-w-0 flex-1! cursor-not-allowed rounded-lg border border-[var(--ln)] bg-[var(--bg)] px-3! py-2! text-sm text-[var(--mut)]"
        />
        <button type="button" disabled className="cursor-not-allowed rounded-lg bg-[var(--ln)] px-4! py-2! text-sm font-bold text-[var(--mut)]">
          Kirim
        </button>
      </form>
    </div>
  );
}
