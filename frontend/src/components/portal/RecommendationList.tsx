import Link from "next/link";
import AiFeedback from "@/components/portal/AiFeedback";
import { AiLabel } from "@/components/portal/EarlyWarningCell";
import { clickHref, type RecommendationItem } from "@/lib/recommendations";

/**
 * Daftar rekomendasi training (dibuat AI): judul program, alasan yang bisa ditelusuri, jadwal terdekat, dan tautan
 * yang melewati pencatat klik. `target` menentukan tujuan tautan (halaman program atau formulir permintaan training).
 */
export default function RecommendationList({
  insightId,
  items,
  target,
  cta,
}: {
  insightId: number;
  items: RecommendationItem[];
  target: (item: RecommendationItem) => string;
  cta: string;
}) {
  return (
    <ul className="m-0! mt-3! grid list-none gap-3 p-0! sm:grid-cols-2 xl:grid-cols-3">
      {items.map((it) => (
        <li key={it.slug} className="flex min-w-0 flex-col gap-2 rounded-xl border border-[var(--ln)] bg-white p-4!">
          <span className="flex flex-wrap items-center gap-1.5">
            {it.category && <small className="text-[12px] font-bold text-[var(--mut)]">{it.category}</small>}
            <AiLabel />
          </span>
          <b className="text-[15px] leading-snug text-[var(--ink)]">{it.title}</b>
          <ul className="m-0! list-disc pl-4! text-[13px] leading-snug text-[var(--mut)]">
            {it.reasons.map((r) => (
              <li key={r}>{r}</li>
            ))}
          </ul>
          {it.next && (
            <small className="text-[12px] text-[var(--mut)]">
              Jadwal terdekat: {it.next.dateLabel} ({it.next.code})
            </small>
          )}
          <Link className="lk mt-auto!" href={clickHref(insightId, it.slug, target(it))}>
            {cta}
          </Link>
        </li>
      ))}
    </ul>
  );
}

/** Keterangan + umpan balik di bawah daftar rekomendasi. */
export function RecommendationFooter({
  insightId,
  myFeedback,
  note,
  question = "Apakah rekomendasi ini membantu?",
}: {
  insightId: number;
  myFeedback: boolean | null;
  note: string;
  question?: string;
}) {
  return (
    <div className="mt-3! flex flex-wrap items-center justify-between gap-2">
      <small className="text-[12px] text-[var(--mut)]">{note}</small>
      <span className="flex items-center gap-2">
        <small className="text-[12px] text-[var(--mut)]">{question}</small>
        <AiFeedback insightId={insightId} initial={myFeedback} />
      </span>
    </div>
  );
}
