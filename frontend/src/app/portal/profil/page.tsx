import ProfileForm from "@/components/portal/ProfileForm";
import { PortalCard } from "@/components/portal/ui";
import { requireUser } from "@/lib/auth";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal/profil — data member (PRD §5.6). */
export default async function PortalProfilePage() {
  const { user } = await requireUser("/portal/profil");
  return (
    <PortalCard title="Profil Member">
      <p className="fh" style={{ marginTop: 6 }}>
        Nomor member <b>{user.member?.memberNumber}</b> • terdaftar sejak {user.member?.since ?? "-"}
      </p>
      <div style={{ marginTop: 20 }}>
        <ProfileForm user={user} />
      </div>
    </PortalCard>
  );
}
