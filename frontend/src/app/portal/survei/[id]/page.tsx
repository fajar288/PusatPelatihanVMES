import { notFound } from "next/navigation";
import { BackLink, PortalCard } from "@/components/portal/ui";
import SurveyForm from "@/components/portal/SurveyForm";
import { api, ApiRequestError, requireUser } from "@/lib/auth";
import type { SurveyDetail } from "@/lib/surveys";

export const instant = false;

/** /portal/survei/[id] - isi survei evaluasi satu kelas (hanya milik sendiri; sudah diisi → tampil ringkas). */
export default async function SurveyPage({ params }: PageProps<"/portal/survei/[id]">) {
  const { id } = await params;
  const { token } = await requireUser(`/portal/survei/${id}`);
  let s: SurveyDetail;
  try {
    s = (await api<{ data: SurveyDetail }>(`/me/surveys/${encodeURIComponent(id)}`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && e.error.status === 404) notFound();
    throw e;
  }

  return (
    <>
      <BackLink href="/portal/survei">Kembali ke Survei</BackLink>
      <PortalCard title={`Survei: ${s.class.title}`} action={<span className={"jst " + (s.status === "pending" ? "wn" : "ok")}>{s.statusLabel}</span>}>
        <p className="fh">
          Kelas {s.class.code}, selesai {s.class.endLabel}. Beri nilai 1 (sangat kurang) sampai 5 (sangat baik).
        </p>
        {s.status === "pending" ? (
          <SurveyForm id={s.id} questions={s.questions} />
        ) : (
          <p className="fok" style={{ marginTop: 16 }}>
            Survei ini sudah Anda isi. Terima kasih atas masukannya.
          </p>
        )}
      </PortalCard>
    </>
  );
}
