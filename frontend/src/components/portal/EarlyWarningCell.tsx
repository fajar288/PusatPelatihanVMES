import AiFeedback from "@/components/portal/AiFeedback";
import type { EarlyWarning } from "@/lib/monitoring";

const PILL: Record<EarlyWarning["level"], "no" | "wn" | "ok"> = { high: "no", medium: "wn", low: "ok" };

/** Label kecil penanda keluaran AI. */
export function AiLabel() {
  return <span className="rounded border border-[var(--ln)] bg-[var(--bg)] px-1.5! py-px! text-[10px] font-bold tracking-wide whitespace-nowrap text-[var(--mut)] uppercase">Dibuat AI</span>;
}

/**
 * Skor peringatan dini satu peserta: tingkat risiko, skor 0-100, alasan utama, label "Dibuat AI", dan umpan balik.
 * `compact` untuk sel tabel (alasan disingkat), selain itu untuk kartu detail.
 */
export default function EarlyWarningCell({ ew, compact = false }: { ew: EarlyWarning | null | undefined; compact?: boolean }) {
  if (!ew) return <span className="fh">-</span>;
  const reasons = compact ? ew.reasons.slice(0, 2) : ew.reasons;
  return (
    <div className="flex min-w-[180px] flex-col items-start gap-1">
      <span className="flex flex-wrap items-center gap-1.5">
        <span className={"jst " + PILL[ew.level]}>{ew.levelLabel}</span>
        <b className="text-[13px] text-[var(--ink)]">{ew.score}/100</b>
        <AiLabel />
      </span>
      {reasons.length > 0 ? (
        <ul className="m-0! list-disc pl-4! text-[12px] leading-snug text-[var(--mut)]">
          {reasons.map((r) => (
            <li key={r}>{r}</li>
          ))}
        </ul>
      ) : (
        <small className="text-[12px] text-[var(--mut)]">Tidak ada faktor yang menonjol.</small>
      )}
      <AiFeedback insightId={ew.insightId} initial={ew.myFeedback} />
    </div>
  );
}
