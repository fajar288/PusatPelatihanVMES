import Link from "next/link";
import { notFound } from "next/navigation";
import { PortalCard } from "@/components/portal/ui";
import ParticipantOverview from "@/components/trainer/ParticipantOverview";
import { api, ApiRequestError } from "@/lib/auth";
import { ENROLLMENT_STATUS, type ParticipantOverview as Overview } from "@/lib/monitoring";
import { requireTrainer } from "@/lib/trainer";

export const instant = false;

/** /trainer/progres/[code]/[enrollment] — progress overview lengkap satu peserta (termasuk draf logbook/penilaian). */
export default async function TrainerParticipantProgress({ params }: PageProps<"/trainer/progres/[code]/[enrollment]">) {
  const { code, enrollment } = await params;
  const { token } = await requireTrainer(`/trainer/progres/${code}/${enrollment}`);
  let o: Overview;
  try {
    o = (await api<{ data: Overview }>(`/trainer/classes/${encodeURIComponent(code)}/progress/${encodeURIComponent(enrollment)}`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    throw e;
  }

  return (
    <>
      <Link className="bk" href={`/trainer/progres/${code}`}>
        ← Progres kelas
      </Link>
      <PortalCard
        title={o.enrollment.name}
        action={
          <span style={{ display: "flex", gap: 8 }}>
            <Link className="jb" href={`/trainer/logbook/${code}`}>
              Logbook
            </Link>
            <Link className="jb" href={`/trainer/penilaian/${code}`}>
              Nilai kompetensi
            </Link>
          </span>
        }
      >
        <p className="fh" style={{ marginTop: 4 }}>
          {o.enrollment.email ?? ""} • {o.class.title} ({o.class.code}) • {ENROLLMENT_STATUS[o.enrollment.status] ?? o.enrollment.status}
          {o.enrollment.finalScore != null ? ` • nilai akhir ${o.enrollment.finalScore}` : ""}
          {o.learning.lastSeenAt ? ` • terakhir belajar ${new Date(o.learning.lastSeenAt).toLocaleDateString("id-ID", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })}` : ""}
        </p>
      </PortalCard>
      <ParticipantOverview o={o} forTrainer />
    </>
  );
}
