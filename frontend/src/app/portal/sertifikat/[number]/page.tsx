import Link from "next/link";
import { notFound } from "next/navigation";
import { PortalCard } from "@/components/portal/ui";
import { api, ApiRequestError, requireUser } from "@/lib/auth";
import { CERT_PILL, type CertificateHistory, type CertificateInfo, HISTORY_LABEL } from "@/lib/certificates";

export const instant = false;

/** /portal/sertifikat/[number] — detail sertifikat: data tercetak, lampiran materi/kompetensi, riwayat penerbitan, unduh & verifikasi. */
export default async function PortalCertificateDetailPage({ params }: PageProps<"/portal/sertifikat/[number]">) {
  const { number } = await params;
  const { token } = await requireUser(`/portal/sertifikat/${number}`);
  let c: (CertificateInfo & { history: CertificateHistory[] }) | null = null;
  try {
    c = (await api<{ data: CertificateInfo & { history: CertificateHistory[] } }>(`/me/certificates/${encodeURIComponent(number)}`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && e.error.status === 404) notFound();
    throw e;
  }
  if (!c) notFound();

  return (
    <>
      <PortalCard
        title={c.programTitle}
        action={<span className={"jst " + (CERT_PILL[c.isExpired ? "expired" : c.status] ?? "wn")}>{c.isExpired ? "Kedaluwarsa" : c.statusLabel}</span>}
      >
        <p className="fh" style={{ marginTop: 6 }}>
          {c.typeLabel} • Nomor <b>{c.number}</b>
        </p>
        <ul className="sp" style={{ marginTop: 14 }}>
          <li>
            <span>Nama pada sertifikat</span>
            <b>{c.recipientName}</b>
          </li>
          {c.classCode && (
            <li>
              <span>Kelas</span>
              <b>{c.classCode}</b>
            </li>
          )}
          {c.periodLabel && (
            <li>
              <span>Periode pelatihan</span>
              <b>
                {c.periodLabel}
                {c.city ? `, ${c.city}` : ""}
              </b>
            </li>
          )}
          {c.hours !== null && (
            <li>
              <span>Durasi</span>
              <b>{c.hours} jam pelajaran</b>
            </li>
          )}
          {c.score !== null && (
            <li>
              <span>Nilai</span>
              <b>
                {c.score}
                {c.predicate ? ` (${c.predicate})` : ""}
              </b>
            </li>
          )}
          <li>
            <span>Tanggal terbit</span>
            <b>{c.issuedLabel ?? "-"}</b>
          </li>
          <li>
            <span>Masa berlaku</span>
            <b>{c.validUntilLabel ? `hingga ${c.validUntilLabel}` : "tanpa batas"}</b>
          </li>
          <li>
            <span>Penerbit</span>
            <b>
              {c.issuer ?? "-"}
              {c.signatory ? ` • ${c.signatory}${c.signatoryTitle ? `, ${c.signatoryTitle}` : ""}` : ""}
            </b>
          </li>
          {c.replacedBy && (
            <li>
              <span>Digantikan oleh</span>
              <b>
                <Link href={`/portal/sertifikat/${encodeURIComponent(c.replacedBy)}`}>{c.replacedBy}</Link>
              </b>
            </li>
          )}
          {c.replaces && (
            <li>
              <span>Menggantikan</span>
              <b>{c.replaces}</b>
            </li>
          )}
        </ul>
        <div className="cert-actions">
          {c.downloadable && (
            <a className="btn" href={`/portal/sertifikat/unduh/${encodeURIComponent(c.number)}`}>
              Unduh PDF
            </a>
          )}
          {c.downloadable && (
            <a className="btn sec" href={`/portal/sertifikat/unduh/${encodeURIComponent(c.number)}?inline=1`} target="_blank" rel="noopener">
              Pratinjau
            </a>
          )}
          <Link className="btn sec" href={`/verifikasi/${encodeURIComponent(c.number)}`}>
            Halaman verifikasi
          </Link>
          <Link className="lk" href="/portal/sertifikat">
            Semua sertifikat
          </Link>
        </div>
        {c.status === "revoked" && c.revokeReason && (
          <p className="fh" style={{ marginTop: 12 }}>
            Dicabut: {c.revokeReason}
          </p>
        )}
      </PortalCard>

      {c.competencies.length > 0 && (
        <PortalCard title={c.type === "competency" ? "Unit kompetensi" : "Materi pelatihan"}>
          <ol style={{ marginTop: 10, paddingLeft: 22, fontSize: 15, lineHeight: 1.7 }}>
            {c.competencies.map((k, i) => (
              <li key={i}>{k}</li>
            ))}
          </ol>
        </PortalCard>
      )}

      <PortalCard title="Riwayat penerbitan">
        {c.history.length ? (
          <ul className="pnotif">
            {c.history.map((h, i) => (
              <li key={i}>
                <time>{h.atLabel}</time>
                <p>
                  <b>{HISTORY_LABEL[h.action] ?? h.action}</b>
                  {h.after && typeof h.after.reason === "string" ? ` — ${h.after.reason}` : ""}
                  {h.after && typeof h.after.replaced_by === "string" ? ` — pengganti ${h.after.replaced_by}` : ""}
                </p>
              </li>
            ))}
          </ul>
        ) : (
          <p className="fh">Belum ada riwayat.</p>
        )}
      </PortalCard>
    </>
  );
}
