import type { Metadata } from "next";
import CatalogHero from "@/components/catalog/CatalogHero";
import VerifyForm from "@/components/certificates/VerifyForm";
import { SITE } from "@/data/site";

export const metadata: Metadata = {
  title: `Verifikasi Sertifikat | ${SITE.name}`,
  description: "Periksa keaslian dan status sertifikat PPTI - VMES Training Center dengan nomor sertifikat atau pindai kode QR pada sertifikat.",
  alternates: { canonical: "/verifikasi" },
};

/** /verifikasi — halaman verifikasi publik (PRD §5.10, AC-10): masukkan nomor → /verifikasi/[nomor]. */
export default function VerifikasiPage() {
  return (
    <div>
      <CatalogHero
        eyebrow="Verifikasi Sertifikat"
        title={
          <>
            Periksa Keaslian <em>Sertifikat</em>
          </>
        }
        desc="Masukkan nomor sertifikat yang tercetak pada dokumen, atau pindai kode QR di pojok sertifikat untuk melihat status penerbitannya."
      />
      <div className="wrap">
        <div className="kbx vbox" style={{ marginTop: 36 }}>
          <h2>Nomor Sertifikat</h2>
          <p className="sdt" style={{ marginTop: 8, color: "var(--mut)", fontWeight: 500 }}>
            Format nomor: PPTI-PST-YYYYMM-00001 (keikutsertaan) atau PPTI-KMP-YYYYMM-00001 (kompetensi).
          </p>
          <div style={{ marginTop: 22 }}>
            <VerifyForm />
          </div>
          <p className="fh" style={{ marginTop: 18 }}>
            Hasil verifikasi menampilkan nama penerima, program, tanggal terbit, masa berlaku, dan status (terbit, dicabut, diganti, atau kedaluwarsa). Sertifikat kompetensi
            diterbitkan terpisah dari sertifikat keikutsertaan.
          </p>
        </div>
      </div>
      <div style={{ height: 60 }} />
    </div>
  );
}
