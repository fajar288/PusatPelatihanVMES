import Link from "next/link";
import { EmptyState, MetaList, PortalCard } from "@/components/portal/ui";
import { CERT_STATUS, type CertificateRow, orgPath } from "@/lib/corporate";
import { corporateApi } from "@/lib/corporate-server";

export const instant = false;

/**
 * /corporate/[org]/sertifikat — status sertifikat dari enrollment yang disponsori (§11, §20.11). Sertifikat tetap milik
 * peserta (unduh PDF hanya dari Portal Member); perusahaan melihat status & nomor untuk verifikasi publik.
 */
export default async function CertificatesPage({ params }: PageProps<"/corporate/[org]/sertifikat">) {
  const { org } = await params;
  const rows = (await corporateApi<{ data: CertificateRow[] }>(orgPath(org, "/certificates"), `/corporate/${org}/sertifikat`)).data;
  return (
    <PortalCard title="Sertifikat">
      <p className="fh">
        Sertifikat dimiliki peserta. Nomor sertifikat yang terbit dapat diverifikasi siapa pun di halaman <Link href="/verifikasi">Verifikasi Sertifikat</Link>.
      </p>
      {rows.length === 0 ? (
        <EmptyState text="Belum ada sertifikat dari training yang disponsori perusahaan." />
      ) : (
        <ul className="sp" style={{ marginTop: 12 }}>
          {rows.map((c) => (
            <li key={c.enrollmentId}>
              <span className="spl">
                <b>{c.name}</b>
                <MetaList compact items={[{ icon: "grad", value: c.program }, { icon: "cal", value: c.schedule }, { icon: "award", value: c.number }, { label: "Terbit", value: c.issuedAt }]} />
              </span>
              <b className="spv">
                <span className={`jst ${c.status === "issued" ? "ok" : c.status === "revoked" ? "no" : "wn"}`}>{CERT_STATUS[c.status ?? ""] ?? c.status ?? "-"}</span>
              </b>
            </li>
          ))}
        </ul>
      )}
    </PortalCard>
  );
}
