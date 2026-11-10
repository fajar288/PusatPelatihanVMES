import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import RegisterForm from "@/components/forms/RegisterForm";
import { SITE } from "@/data/site";
import { getSchedules } from "@/lib/cms";

export const metadata: Metadata = {
  title: `Formulir Pendaftaran | ${SITE.name}`,
  description: "Daftar training PPTI - VMES Training Center: pilih jadwal, isi data peserta, dan terima konfirmasi dari admin.",
  alternates: { canonical: "/daftar" },
  robots: { index: false },
};

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

async function FormFromParams({ searchParams }: Pick<PageProps<"/daftar">, "searchParams">) {
  const [sp, schedules] = await Promise.all([searchParams, getSchedules()]);
  return <RegisterForm schedules={schedules} selectedId={first(sp.jadwal)} />;
}

async function FormStatic() {
  return <RegisterForm schedules={await getSchedules()} />;
}

/** /daftar?jadwal= — formulir pendaftaran (Phase 1E). Versi awal mengirim data ke WhatsApp admin; API Laravel menyusul. */
export default function DaftarPage({ searchParams }: PageProps<"/daftar">) {
  return (
    <>
      <section className="ph0">
        <div className="wrap">
          <h1>Formulir Pendaftaran</h1>
          <p>
            Lengkapi data di bawah ini. Belum yakin? Baca <Link href="/cara-pendaftaran">cara pendaftaran</Link> atau <Link href="/faq">FAQ</Link> terlebih dahulu.
          </p>
        </div>
      </section>
      <div className="single">
        <div className="wrap">
          <div className="in" style={{ maxWidth: 820 }}>
            <div className="kbx" style={{ marginTop: 0 }}>
              <Suspense fallback={<FormStatic />}>
                <FormFromParams searchParams={searchParams} />
              </Suspense>
            </div>
            <div style={{ height: 40 }} />
          </div>
        </div>
      </div>
    </>
  );
}
