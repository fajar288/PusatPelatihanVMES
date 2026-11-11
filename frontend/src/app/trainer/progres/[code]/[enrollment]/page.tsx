import Link from "next/link";
import { notFound } from "next/navigation";
import { BackLink, MetaList, PortalCard } from "@/components/portal/ui";
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
      <BackLink href={`/trainer/progres/${code}`}>Kembali ke Progres kelas</BackLink>
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
        <MetaList
          items={[
            { icon: "mail", value: o.enrollment.email },
            { icon: "grad", value: `${o.class.title} (${o.class.code})` },
            { label: "Status", value: ENROLLMENT_STATUS[o.enrollment.status] ?? o.enrollment.status },
            o.enrollment.finalScore != null && { icon: "award", label: "Nilai akhir", value: o.enrollment.finalScore },
            !!o.learning.lastSeenAt && {
              icon: "clock",
              label: "Terakhir belajar",
              value: new Date(o.learning.lastSeenAt).toLocaleDateString("id-ID", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }),
            },
          ]}
        />
      </PortalCard>
      <ParticipantOverview o={o} forTrainer />
    </>
  );
}
