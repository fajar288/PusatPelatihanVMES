import { notFound } from "next/navigation";
import { BackLink, MetaList, PortalCard } from "@/components/portal/ui";
import AssessmentForm from "@/components/trainer/AssessmentForm";
import { api, ApiRequestError } from "@/lib/auth";
import type { AssessmentsData } from "@/lib/monitoring";
import { requireTrainer } from "@/lib/trainer";

export const instant = false;

/** /trainer/penilaian/[code] — rubrik program + penilaian kompetensi tiap peserta kelas. */
export default async function TrainerClassAssessments({ params }: PageProps<"/trainer/penilaian/[code]">) {
  const { code } = await params;
  const { token } = await requireTrainer(`/trainer/penilaian/${code}`);
  let d: AssessmentsData;
  try {
    d = (await api<{ data: AssessmentsData }>(`/trainer/classes/${encodeURIComponent(code)}/assessments`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    throw e;
  }
  const validated = d.participants.filter((p) => p.assessment.status === "validated").length;
  const competent = d.participants.filter((p) => p.assessment.status === "validated" && p.assessment.isCompetent).length;

  return (
    <>
      <BackLink href="/trainer/penilaian">Kembali ke Penilaian</BackLink>
      <PortalCard
        title={`Penilaian — ${d.class.title}`}
        action={
          <span className={"jst " + (validated === d.participants.length && d.participants.length > 0 ? "ok" : "wn")}>
            {validated}/{d.participants.length} tervalidasi, {competent} kompeten
          </span>
        }
      >
        {d.rubric ? (
          <>
            <MetaList
              items={[
                { icon: "check", label: "Rubrik", value: d.rubric.name },
                { label: "Ambang kompeten", value: `${d.rubric.threshold}%` },
                { label: "Kriteria", value: d.rubric.criteria.length },
              ]}
            />
            <p className="fh" style={{ marginTop: 8 }}>
              {d.rubric.description ? `${d.rubric.description} ` : ""}Rubrik dikelola admin (menu Monitoring › Rubrik penilaian).
            </p>
            <ul className="sp" style={{ marginBottom: 0 }}>
              {d.rubric.criteria.map((c) => (
                <li key={c.id}>
                  <span className="spl">
                    <b>{c.name}</b>
                    {c.competency ? <small>Kompetensi: {c.competency}</small> : null}
                    {c.description ? <small>{c.description}</small> : null}
                  </span>
                  <b className="spv">
                    Maks. {c.maxScore}
                    {c.weight !== 1 ? <small>Bobot {c.weight}</small> : null}
                  </b>
                </li>
              ))}
            </ul>
          </>
        ) : (
          <p className="fh">Belum ada peserta di kelas ini.</p>
        )}
      </PortalCard>
      {d.participants.map((item) => (
        <AssessmentForm key={`${item.participant.id}-${item.assessment.status}-${item.assessment.assessedAt ?? ""}`} code={code} item={item} />
      ))}
    </>
  );
}
