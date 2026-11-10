import type { Metadata } from "next";
import TrainerNav from "@/components/trainer/TrainerNav";
import { SITE } from "@/data/site";
import { requireTrainer } from "@/lib/trainer";

export const metadata: Metadata = { title: `Portal Trainer | ${SITE.name}`, robots: { index: false } };

export const instant = false;

/** /trainer/* — portal trainer & assessor (PRD §5.7): kelas yang ditugaskan, peserta, kehadiran, profil. */
export default async function TrainerLayout({ children }: LayoutProps<"/trainer">) {
  const { profile } = await requireTrainer();
  return (
    <>
      <section className="ph0">
        <div className="wrap">
          <h1>Portal Trainer</h1>
          <p>Kelas yang Anda ampu, daftar peserta, pencatatan kehadiran, dan profil trainer.</p>
        </div>
      </section>
      <div className="wrap pgrid">
        <TrainerNav name={profile.name} title={profile.title} />
        <section className="pmain">{children}</section>
      </div>
      <div style={{ height: 60 }} />
    </>
  );
}
