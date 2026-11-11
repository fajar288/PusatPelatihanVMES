import ProfileForm from "@/components/portal/ProfileForm";
import { MetaList, PortalCard } from "@/components/portal/ui";
import { requireUser } from "@/lib/auth";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal/profil — data member (PRD §5.6). */
export default async function PortalProfilePage() {
  const { user } = await requireUser("/portal/profil");
  return (
    <PortalCard title="Profil Member">
      <MetaList
        items={[
          { icon: "users", label: "Nomor member", value: user.member?.memberNumber },
          { icon: "cal", label: "Terdaftar sejak", value: user.member?.since ?? "-" },
        ]}
      />
      <div style={{ marginTop: 20 }}>
        <ProfileForm user={user} />
      </div>
    </PortalCard>
  );
}
