import { PortalCard } from "@/components/portal/ui";
import TrainerProfileForm from "@/components/trainer/TrainerProfileForm";
import { requireTrainer } from "@/lib/trainer";

export const instant = false;

/** /trainer/profil — profil trainer (keahlian, pendidikan, sertifikasi, portofolio). */
export default async function TrainerProfilePage() {
  const { profile } = await requireTrainer("/trainer/profil");
  return (
    <PortalCard title="Profil Trainer">
      <p className="fh" style={{ marginTop: 6 }}>
        {profile.isAssessor ? "Trainer & Assessor" : "Trainer"} • {profile.email ?? ""}
      </p>
      <div style={{ marginTop: 20 }}>
        <TrainerProfileForm p={profile} />
      </div>
    </PortalCard>
  );
}
