import type { Metadata } from "next";
import Link from "next/link";
import CatalogHero from "@/components/catalog/CatalogHero";
import VerifyForm from "@/components/certificates/VerifyForm";
import Icon from "@/components/ui/Icon";
import { SITE } from "@/data/site";
import { api, ApiRequestError } from "@/lib/auth";
import { CERT_PILL, type VerificationResult } from "@/lib/certificates";

// Hasil per nomor (data dinamis dari API, tidak di-prerender); robots noindex agar data penerima tidak diindeks.
export const instant = false;

export async function generateMetadata({ params }: PageProps<"/verifikasi/[nomor]">): Promise<Metadata> {
  const { nomor } = await params;
  return { title: `Verifikasi ${decodeURIComponent(nomor)} | ${SITE.name}`, robots: { index: false } };
}

async function lookup(nomor: string, code: string | null): Promise<VerificationResult | null> {
  try {
    const r = await api<{ data: VerificationResult }>(`/verify/${encodeURIComponent(nomor)}${code ? `?k=${encodeURIComponent(code)}` : ""}`);
    return r.data;
  } catch (e) {
    if (e instanceof ApiRequestError && e.error.status === 404) return null;
    throw e;
  }
}

/** /verifikasi/[nomor]?k= — hasil verifikasi publik (AC-10): status, penerima, program, tanggal, masa berlaku. */
export default async function VerifikasiHasilPage({ params, searchParams }: PageProps<"/verifikasi/[nomor]">) {
  const { nomor } = await params;
  const sp = await searchParams;
  const code = typeof sp.k === "string" ? sp.k : null;
  const number = decodeURIComponent(nomor);
  const r = await lookup(number, code);
  const tone = r ? (r.valid ? "ok" : r.status === "pending" || r.status === "expired" ? "wn" : "no") : "no";
  const headline = !r
    ? "Nomor sertifikat tidak ditemukan"
    : r.valid
      ? "Sertifikat asli dan masih berlaku"
      : r.status === "expired"
        ? "Sertifikat asli, masa berlaku telah habis"
        : r.status === "pending"
          ? "Sertifikat belum diterbitkan"
          : r.status === "replaced"
            ? "Sertifikat telah diganti dengan nomor baru"
            : "Sertifikat telah dicabut";

  return (
    <div>
      <CatalogHero
        eyebrow="Verifikasi Sertifikat"
        title={
          <>
            Hasil Verifikasi <em>{number}</em>
          </>
        }
        desc="Data berikut diambil langsung dari basis data penerbitan sertifikat PPTI - VMES Training Center."
      />
      <div className="wrap">
        <div className={`kbx vbox vres ${tone}`} style={{ marginTop: 36 }}>
          <div className="vhead">
            <span className="vicon" aria-hidden="true">
              <Icon name={tone === "ok" ? "check" : "shield"} />
            </span>
            <div>
              <h2>{headline}</h2>
              {r && (
                <p className="fh" style={{ marginTop: 4 }}>
                  <span className={"jst " + (CERT_PILL[r.status] ?? "wn")}>{r.statusLabel}</span>
                  {r.codeMatches === false ? <span className="jst no" style={{ marginLeft: 8 }}>Kode QR tidak cocok</span> : null}
                  {r.codeMatches === true ? <span className="jst ok" style={{ marginLeft: 8 }}>Kode QR cocok</span> : null}
                </p>
              )}
            </div>
          </div>

          {!r && (
            <p className="fh" style={{ marginTop: 16 }}>
              Pastikan nomor ditulis persis seperti pada sertifikat. Bila nomor benar namun tidak ditemukan, hubungi kami melalui halaman <Link href="/kontak">Kontak</Link>.
            </p>
          )}

          {r && (
            <ul className="sp vlist">
              <li>
                <span>Nomor</span>
                <b>{r.number}</b>
              </li>
              <li>
                <span>Jenis</span>
                <b>{r.typeLabel}</b>
              </li>
              {r.recipientName && (
                <li>
                  <span>Nama penerima</span>
                  <b>{r.recipientName}</b>
                </li>
              )}
              <li>
                <span>Program</span>
                <b>
                  {r.programTitle}
                  {r.classCode ? ` (${r.classCode})` : ""}
                </b>
              </li>
              {r.periodLabel && (
                <li>
                  <span>Periode pelatihan</span>
                  <b>
                    {r.periodLabel}
                    {r.city ? `, ${r.city}` : ""}
                  </b>
                </li>
              )}
              {r.hours !== null && (
                <li>
                  <span>Durasi</span>
                  <b>{r.hours} jam pelajaran</b>
                </li>
              )}
              {r.predicate && (
                <li>
                  <span>Predikat</span>
                  <b>{r.predicate}</b>
                </li>
              )}
              {r.issuedLabel && (
                <li>
                  <span>Tanggal terbit</span>
                  <b>{r.issuedLabel}</b>
                </li>
              )}
              <li>
                <span>Masa berlaku</span>
                <b>{r.validUntilLabel ? `hingga ${r.validUntilLabel}` : "tanpa batas"}</b>
              </li>
              {r.issuer && (
                <li>
                  <span>Penerbit</span>
                  <b className="spv">
                    {r.issuer}
                    {r.signatory && <small>{r.signatory}</small>}
                  </b>
                </li>
              )}
              {r.replacedBy && (
                <li>
                  <span>Nomor pengganti</span>
                  <b>
                    <Link href={`/verifikasi/${encodeURIComponent(r.replacedBy)}`}>{r.replacedBy}</Link>
                  </b>
                </li>
              )}
            </ul>
          )}

          {r && r.competencies.length > 0 && (
            <>
              <p className="fh" style={{ marginTop: 18, fontWeight: 800 }}>
                {r.type === "competency" ? "Unit kompetensi" : "Materi pelatihan"}
              </p>
              <ol style={{ marginTop: 6, paddingLeft: 22, fontSize: 14.5, lineHeight: 1.7 }}>
                {r.competencies.map((k, i) => (
                  <li key={i}>{k}</li>
                ))}
              </ol>
            </>
          )}
        </div>

        <div className="kbx vbox" style={{ marginTop: 24 }}>
          <h2>Periksa nomor lain</h2>
          <div style={{ marginTop: 18 }}>
            <VerifyForm />
          </div>
        </div>
      </div>
      <div style={{ height: 60 }} />
    </div>
  );
}
