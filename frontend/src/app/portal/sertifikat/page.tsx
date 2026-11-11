import Link from "next/link";
import { EmptyState, MetaList, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import { CERT_PILL, type CertificateInfo, MEMBER_STATUS } from "@/lib/certificates";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal/sertifikat — My Certificates (PRD §5.6, §5.10): unduh PDF, tautan verifikasi, status (terbit/dicabut/diganti). */
export default async function PortalCertificatesPage() {
  const { user, token } = await requireUser("/portal/sertifikat");
  const items = (await api<{ data: CertificateInfo[] }>("/me/certificates", { token })).data;
  const valid = items.filter((c) => c.isValid).length;

  return (
    <>
      <div className="kic pstats">
        <div className="kic1">
          <small>Status keanggotaan</small>
          <b>{MEMBER_STATUS[user.member?.status ?? "registered"]}</b>
        </div>
        <div className="kic1">
          <small>Sertifikat berlaku</small>
          <b>{valid}</b>
        </div>
        <div className="kic1">
          <small>Seluruh riwayat</small>
          <b>{items.length}</b>
        </div>
      </div>

      <PortalCard
        title="Sertifikat Saya"
        action={
          <Link className="lk" href="/verifikasi">
            Cek keaslian sertifikat
          </Link>
        }
      >
        {items.length === 0 && (
          <EmptyState text="Belum ada sertifikat terbit. Sertifikat diterbitkan setelah kelas selesai dan Anda dinyatakan lulus sesuai aturan program." href="/portal/pelatihan" cta="Lihat Pelatihan Saya" />
        )}
        {items.map((c) => (
          <div className="kbx cert-card" key={c.id} style={{ marginTop: 18, padding: 22 }}>
            <div className="pcard-h">
              <h3 style={{ fontSize: 18 }}>
                <Link href={`/portal/sertifikat/${encodeURIComponent(c.number)}`}>{c.programTitle}</Link>
              </h3>
              <span className={"jst " + (CERT_PILL[c.isExpired ? "expired" : c.status] ?? "wn")}>{c.isExpired ? "Kedaluwarsa" : c.statusLabel}</span>
            </div>
            <MetaList
              items={[
                { icon: "award", label: c.typeLabel, value: c.number },
                { icon: "code", label: "Kelas", value: c.classCode },
                { icon: "cal", label: "Terbit", value: c.issuedLabel },
                { icon: "clock", label: "Berlaku", value: c.validUntilLabel ? `hingga ${c.validUntilLabel}` : "tanpa batas" },
                { icon: "star", label: "Predikat", value: c.predicate },
              ]}
            />
            {c.status === "replaced" && c.replacedBy && (
              <p className="fh" style={{ marginTop: 6 }}>
                Digantikan oleh sertifikat <b>{c.replacedBy}</b>.
              </p>
            )}
            {c.status === "revoked" && (
              <p className="fh" style={{ marginTop: 6 }}>
                Dicabut{c.revokeReason ? `: ${c.revokeReason}` : ""}.
              </p>
            )}
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
              <Link className="lk" href={`/portal/sertifikat/${encodeURIComponent(c.number)}`}>
                Detail & riwayat
              </Link>
            </div>
          </div>
        ))}
      </PortalCard>

      <PortalCard title="Alumni PPTI">
        <p className="fh" style={{ marginTop: 8 }}>
          Sebagai alumni, Anda dapat mendaftar program lanjutan dengan akun yang sama; riwayat pelatihan, nilai, dan sertifikat lama tetap tersimpan di portal ini.{" "}
          <Link href="/jadwal">Lihat jadwal training terbaru</Link>.
        </p>
      </PortalCard>
    </>
  );
}
