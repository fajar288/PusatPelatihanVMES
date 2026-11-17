import { notFound } from "next/navigation";
import AiFeedback from "@/components/portal/AiFeedback";
import { AiLabel } from "@/components/portal/EarlyWarningCell";
import { BackLink, EmptyState, PortalCard } from "@/components/portal/ui";
import { api, ApiRequestError } from "@/lib/auth";
import { fmtScore, type SurveyClassReport } from "@/lib/surveys";
import { requireTrainer } from "@/lib/trainer";

export const instant = false;

/** /trainer/survei/[code] - hasil survei evaluasi kelas: skor rata-rata, komentar tanpa nama, ringkasan AI yang disetujui admin. */
export default async function TrainerSurveyPage({ params }: PageProps<"/trainer/survei/[code]">) {
  const { code } = await params;
  const { token } = await requireTrainer(`/trainer/survei/${code}`);
  let d: SurveyClassReport;
  try {
    d = (await api<{ data: SurveyClassReport }>(`/trainer/classes/${encodeURIComponent(code)}/survey`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    throw e;
  }
  const r = d.report;

  return (
    <>
      <BackLink href={`/trainer/progres/${code}`}>Kembali ke Progres kelas</BackLink>
      <div className="kic pstats">
        <div className="kic1">
          <small>Survei terisi</small>
          <b>
            {r.submitted}/{r.invited}
          </b>
        </div>
        <div className="kic1">
          <small>Tingkat respons</small>
          <b>{r.responseRate === null ? "-" : `${r.responseRate}%`}</b>
        </div>
        <div className="kic1">
          <small>Rata-rata skor</small>
          <b>{fmtScore(r.average)}</b>
        </div>
      </div>

      {d.summary && (
        <PortalCard title="Ringkasan umpan balik" action={<AiLabel />}>
          <p style={{ marginTop: 8 }}>{d.summary.summary}</p>
          {(
            [
              ["Kekuatan", d.summary.strengths],
              ["Perlu diperbaiki", d.summary.improvements],
              ["Usulan tindakan", d.summary.actions],
            ] as const
          ).map(([title, items]) =>
            items.length > 0 ? (
              <div key={title} style={{ marginTop: 12 }}>
                <b>{title}</b>
                <ul className="m-0! mt-1! list-disc pl-5!">
                  {items.map((it) => (
                    <li key={it}>{it}</li>
                  ))}
                </ul>
              </div>
            ) : null,
          )}
          <div className="mt-3! flex flex-wrap items-center gap-2">
            <small className="fh">Disusun AI dari survei kelas ini dan sudah ditinjau tim PPTI. Apakah ringkasan ini membantu?</small>
            <AiFeedback insightId={d.summary.insightId} initial={d.summary.myFeedback} />
          </div>
        </PortalCard>
      )}

      <PortalCard title={`Survei evaluasi: ${d.class.title}`}>
        {r.submitted === 0 ? (
          <EmptyState text="Belum ada peserta yang mengisi survei. Undangan dikirim otomatis setelah kelas selesai." />
        ) : r.detailHidden ? (
          <p className="fh" style={{ marginTop: 8 }}>
            Rincian skor dan komentar tampil setelah minimal {r.minResponses} peserta mengisi survei, agar jawaban tidak bisa dikenali satu per satu.
          </p>
        ) : (
          <>
            <ul className="sp">
              {r.questions.map((q) => (
                <li key={q.key}>
                  <span>{q.label}</span>
                  <b>{fmtScore(q.average)}</b>
                </li>
              ))}
            </ul>
            <b>Komentar peserta</b>
            {r.comments.length === 0 ? (
              <p className="fh">Belum ada komentar.</p>
            ) : (
              <ul className="m-0! mt-2! grid list-none gap-2 p-0!">
                {r.comments.map((c, i) => (
                  <li key={i} className="rounded-lg border border-[var(--ln)] bg-white p-3!">
                    <small className="block text-[12px] text-[var(--mut)]">{c.question}</small>
                    {c.text}
                  </li>
                ))}
              </ul>
            )}
            <p className="fh" style={{ marginTop: 12, fontSize: 13 }}>
              Komentar ditampilkan tanpa nama peserta.
            </p>
          </>
        )}
      </PortalCard>
    </>
  );
}
