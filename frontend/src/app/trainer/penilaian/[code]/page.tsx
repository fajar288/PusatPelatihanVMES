import Link from "next/link";
import { notFound } from "next/navigation";
import { PortalCard } from "@/components/portal/ui";
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
      <Link className="bk" href="/trainer/penilaian">
        ← Penilaian
      </Link>
      <PortalCard
        title={`Penilaian — ${d.class.title}`}
        action={
          <span className={"jst " + (validated === d.participants.length && d.participants.length > 0 ? "ok" : "wn")}>
            {validated}/{d.participants.length} tervalidasi • {competent} kompeten
          </span>
        }
      >
        {d.rubric ? (
          <>
            <p className="fh" style={{ marginTop: 4 }}>
              Rubrik: <b>{d.rubric.name}</b> • ambang kompeten {d.rubric.threshold}% • {d.rubric.criteria.length} kriteria. {d.rubric.description ?? ""} Rubrik dikelola admin (menu Monitoring › Rubrik penilaian).
            </p>
            <ul className="sp" style={{ marginBottom: 0 }}>
              {d.rubric.criteria.map((c) => (
                <li key={c.id}>
                  <span>
                    {c.name}
                    {c.competency ? <small className="fh"> • {c.competency}</small> : null}
                    {c.description ? <small className="fh"> — {c.description}</small> : null}
                  </span>
                  <b>
                    maks. {c.maxScore}
                    {c.weight !== 1 ? ` • bobot ${c.weight}` : ""}
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
