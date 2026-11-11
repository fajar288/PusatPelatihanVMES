import { MetaList, PortalCard } from "@/components/portal/ui";
import TrainerProfileForm from "@/components/trainer/TrainerProfileForm";
import { requireTrainer } from "@/lib/trainer";

export const instant = false;

/** /trainer/profil — profil trainer (keahlian, pendidikan, sertifikasi, portofolio). */
export default async function TrainerProfilePage() {
  const { profile } = await requireTrainer("/trainer/profil");
  return (
    <PortalCard title="Profil Trainer">
      <MetaList
        items={[
          { icon: "award", label: "Peran", value: profile.isAssessor ? "Trainer & Assessor" : "Trainer" },
          { icon: "mail", value: profile.email },
        ]}
      />
      <div style={{ marginTop: 20 }}>
        <TrainerProfileForm p={profile} />
      </div>
    </PortalCard>
  );
}
