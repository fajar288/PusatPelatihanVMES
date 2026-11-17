import Link from "next/link";
import { RecommendationFooter } from "@/components/portal/RecommendationList";
import { PortalCard } from "@/components/portal/ui";
import type { CorporateInsight } from "@/lib/corporate";

const PRIORITY: Record<CorporateInsight["actions"][number]["priority"], { label: string; cls: string }> = {
  tinggi: { label: "Prioritas tinggi", cls: "no" },
  sedang: { label: "Prioritas sedang", cls: "wn" },
  rendah: { label: "Prioritas rendah", cls: "ok" },
};

const fmt = (iso: string | null) =>
  iso ? new Intl.DateTimeFormat("id-ID", { day: "numeric", month: "long", year: "numeric", timeZone: "Asia/Jakarta" }).format(new Date(iso)) : null;

/**
 * Insight kompetensi organisasi (dibuat AI dari angka gabungan, sudah ditinjau tim PPTI). Program yang disarankan
 * selalu berasal dari katalog; tombolnya mengarah ke formulir permintaan training bila peran pengguna boleh mengajukan.
 */
export default function CorporateInsightCard({ org, insight, canRequest }: { org: string; insight: CorporateInsight; canRequest: boolean }) {
  const approved = fmt(insight.approvedAt);

  return (
    <PortalCard title="Insight kompetensi" action={<span className="jst">Dibuat AI</span>}>
      <p className="fh">
        Disusun AI dari angka gabungan matriks kompetensi dan hasil training yang disponsori perusahaan, tanpa data per karyawan
        {approved ? `, dan sudah ditinjau tim PPTI pada ${approved}` : ""}.
      </p>
      {insight.summary && <p className="mt-3! mb-0! leading-relaxed">{insight.summary}</p>}

      {insight.keyGaps.length > 0 && (
        <div className="mt-4!">
          <b className="block text-[15px]">Gap utama</b>
          <ul className="m-0! mt-1.5! list-disc pl-5!">
            {insight.keyGaps.map((g) => (
              <li key={g.competency} className="mt-1! break-words">
                <b>{g.competency}</b>: {g.finding}
              </li>
            ))}
          </ul>
        </div>
      )}

      {insight.actions.length > 0 && (
        <div className="mt-4!">
          <b className="block text-[15px]">Usulan tindakan</b>
          <ol className="m-0! mt-1.5! flex list-none flex-col gap-2.5 p-0!">
            {insight.actions.map((a, i) => (
              <li key={i} className="min-w-0 rounded-lg border border-[var(--ln)] px-3.5! py-3!">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  {/* basis minimum: di layar sempit lencana prioritas turun ke baris berikutnya, judul tidak terjepit. */}
                  <b className="min-w-0 shrink! grow! basis-[14rem]! break-words">{a.action}</b>
                  <span className={"jst " + PRIORITY[a.priority].cls}>{PRIORITY[a.priority].label}</span>
                </div>
                {a.reason && <p className="m-0! mt-1! text-sm text-[var(--mut)]">{a.reason}</p>}
                {a.program && (
                  <p className="m-0! mt-2! text-sm">
                    Program:{" "}
                    <Link href={canRequest ? `/corporate/${org}/permintaan/baru?paket=${encodeURIComponent(a.program.slug)}` : `/kursus/${a.program.slug}`} className="font-bold">
                      {a.program.title}
                    </Link>
                    {canRequest && <span className="text-[var(--mut)]"> (ajukan permintaan training)</span>}
                  </p>
                )}
              </li>
            ))}
          </ol>
        </div>
      )}

      {insight.limitations && <p className="fh mt-3! text-[13px]">Catatan data: {insight.limitations}</p>}
      <RecommendationFooter
        insightId={insight.insightId}
        myFeedback={insight.myFeedback}
        note="Insight ini bahan pertimbangan; keputusan training tetap di tangan perusahaan."
        question="Apakah insight ini membantu?"
      />
    </PortalCard>
  );
}
