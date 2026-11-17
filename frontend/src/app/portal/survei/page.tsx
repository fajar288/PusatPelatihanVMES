import Link from "next/link";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { SurveyRow } from "@/lib/surveys";

export const instant = false;

/** /portal/survei - survei evaluasi pasca-kelas milik peserta (belum diisi lebih dulu). */
export default async function SurveyListPage() {
  const { token } = await requireUser("/portal/survei");
  const rows = (await api<{ data: SurveyRow[] }>("/me/surveys", { token })).data;
  const pending = rows.filter((r) => r.status === "pending");

  return (
    <PortalCard title="Survei evaluasi">
      <p className="fh">Setelah kelas selesai, kami meminta penilaian singkat Anda. Masukan ini dipakai untuk memperbaiki materi, trainer, dan fasilitas.</p>
      {rows.length === 0 ? (
        <div style={{ marginTop: 14 }}>
          <EmptyState text="Belum ada survei. Undangan survei muncul setelah kelas Anda selesai." />
        </div>
      ) : (
        <ul className="m-0! mt-4! grid list-none gap-3 p-0!">
          {[...pending, ...rows.filter((r) => r.status !== "pending")].map((r) => (
            <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[var(--ln)] bg-white p-4!">
              <span className="min-w-0">
                <b className="block text-[15px] text-[var(--ink)]">{r.class.title}</b>
                <small className="text-[13px] text-[var(--mut)]">
                  {r.class.code} · selesai {r.class.endLabel}
                </small>
              </span>
              <span className="flex items-center gap-3">
                <span className={"jst " + (r.status === "pending" ? "wn" : "ok")}>{r.statusLabel}</span>
                {r.status === "pending" && (
                  <Link className="btn" href={`/portal/survei/${r.id}`}>
                    Isi survei
                  </Link>
                )}
              </span>
            </li>
          ))}
        </ul>
      )}
    </PortalCard>
  );
}
