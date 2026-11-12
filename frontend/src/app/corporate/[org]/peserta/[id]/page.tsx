import ParticipantOverview from "@/components/trainer/ParticipantOverview";
import { BackLink, MetaList, PortalCard } from "@/components/portal/ui";
import { CERT_STATUS, type CorporateProgress, orgPath } from "@/lib/corporate";
import { corporateApi } from "@/lib/corporate-server";
import type { ParticipantOverview as Overview } from "@/lib/monitoring";
import { ENROLLMENT_STATUS } from "@/lib/monitoring";

export const instant = false;

/**
 * /corporate/[org]/peserta/[id] — ringkasan progres training yang disponsori (§20.10): kehadiran, LMS, nilai,
 * praktik, kompetensi tervalidasi. Catatan privat trainer & catatan internal VMES tidak pernah dikirim API (§20.11).
 */
export default async function ParticipantProgressPage({ params }: PageProps<"/corporate/[org]/peserta/[id]">) {
  const { org, id } = await params;
  const p = (await corporateApi<{ data: CorporateProgress }>(orgPath(org, `/enrollments/${encodeURIComponent(id)}`), `/corporate/${org}/peserta/${id}`)).data;
  const overview = { ...p, enrollment: { ...p.enrollment, email: null }, notes: p.notes.map((n) => ({ ...n, observation: null, trainer: null })) } as Overview;

  return (
    <>
      <BackLink href={`/corporate/${org}/peserta`}>Kembali ke Peserta</BackLink>
      <PortalCard title={p.participant.name} action={<span className="jst wn">{ENROLLMENT_STATUS[p.enrollment.status] ?? p.enrollment.status}</span>}>
        <MetaList
          items={[
            { icon: "grad", label: "Program", value: p.class.title },
            { icon: "cal", label: "Kelas", value: `${p.class.code} · ${p.class.dateLabel} – ${p.class.endLabel}` },
            { label: "Nomor member", value: p.participant.memberNumber },
            { label: "Employee ID", value: p.participant.employeeId },
            { label: "Jabatan", value: p.participant.position },
            { label: "Unit", value: p.participant.unit },
            !!p.certificate && { icon: "award", label: "Sertifikat", value: `${CERT_STATUS[p.certificate.status] ?? p.certificate.status}${p.certificate.number ? ` · ${p.certificate.number}` : ""}` },
          ]}
        />
        <p className="fh" style={{ marginTop: 10, fontSize: 13 }}>
          Ringkasan ini hanya untuk training yang disponsori perusahaan. Logbook &amp; penilaian kompetensi tampil setelah divalidasi VMES; catatan pribadi trainer tidak ditampilkan.
        </p>
      </PortalCard>
      <ParticipantOverview o={overview} />
    </>
  );
}
