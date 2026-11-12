import type { Metadata } from "next";
import { SITE } from "@/data/site";
import { requireUser } from "@/lib/auth";

export const metadata: Metadata = { title: `Corporate Portal | ${SITE.name}`, robots: { index: false } };

// Area per-pengguna (cookie sesi) → diblokir di server agar tamu mendapat redirect HTTP ke /login.
export const instant = false;

/** /corporate/* — workspace perusahaan (Roadmap 5 §4, §20). Login memakai akun yang sama dengan Portal Member. */
export default async function CorporateLayout({ children }: LayoutProps<"/corporate">) {
  await requireUser("/corporate");
  return (
    <>
      <section className="ph0">
        <div className="wrap">
          <h1>Corporate Portal</h1>
          <p>Permintaan training, penugasan karyawan, progres peserta yang disponsori perusahaan, sertifikat, dan pengguna perusahaan.</p>
        </div>
      </section>
      {children}
      <div style={{ height: 60 }} />
    </>
  );
}
