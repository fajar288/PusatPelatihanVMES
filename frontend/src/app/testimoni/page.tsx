import type { Metadata } from "next";
import Link from "next/link";
import CatalogHero from "@/components/catalog/CatalogHero";
import Testimonials from "@/components/home/Testimonials";
import { getTestimonials } from "@/lib/cms";

export const metadata: Metadata = {
  title: "Testimoni Peserta",
  description: "Pengalaman alumni dan profesional yang telah mengikuti training di PPTI - VMES Training Center.",
  alternates: { canonical: "/testimoni" },
};

/**
 * /testimoni - menu "Testimonials" (PRD struktur menu publik). Memakai section .ts beranda (featured + daftar kartu,
 * klik kartu untuk menukar featured) dengan seluruh testimoni yang sudah disetujui di CMS.
 */
export default async function TestimoniPage() {
  const items = await getTestimonials();
  return (
    <div>
      <CatalogHero
        eyebrow="Testimoni"
        title={
          <>
            Cerita <em>Alumni</em> PPTI - VMES
          </>
        }
        desc="Pengalaman peserta dari berbagai perusahaan dan instansi setelah mengikuti training di PPTI - VMES Training Center."
      />

      {items.length > 0 ? (
        <Testimonials items={items} />
      ) : (
        <section className="sec">
          <div className="wrap">
            <p className="nores">Belum ada testimoni yang ditampilkan.</p>
          </div>
        </section>
      )}

      <section className="sec alt">
        <div className="wrap">
          <div className="cn">
            <span className="eb">Mulai Sekarang</span>
            <h2 className="tt">Siap Menjadi Alumni Berikutnya?</h2>
            <p className="sbt">Pilih program yang sesuai kebutuhan Anda atau tim, lalu daftar langsung secara online.</p>
          </div>
          <div className="kbt mgr-cta">
            <Link className="kb1" href="/kursus">
              Lihat Katalog Training
            </Link>
            <Link className="kb2" href="/mitra">
              Mitra & Principal
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}
