import Link from "next/link";
import ProfileForm from "@/components/portal/ProfileForm";
import ProfilePhotoForm from "@/components/portal/ProfilePhotoForm";
import { MetaList, PortalCard } from "@/components/portal/ui";
import { requireUser } from "@/lib/auth";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal/profil: data member + tautan ke halaman Privasi & Data (unduh dan hapus data pribadi). */
export default async function PortalProfilePage() {
  const { user } = await requireUser("/portal/profil");
  return (
    <>
      <PortalCard title="Profil Member">
        <MetaList
          items={[
            { icon: "users", label: "Nomor member", value: user.member?.memberNumber },
            { icon: "cal", label: "Terdaftar sejak", value: user.member?.since ?? "-" },
          ]}
        />
        <div style={{ marginTop: 20 }}>
          <ProfilePhotoForm name={user.name} photoVersion={user.member?.photoVersion ?? null} />
        </div>
        <div style={{ marginTop: 20 }}>
          <ProfileForm user={user} />
        </div>
      </PortalCard>
      <PortalCard title="Data Pribadi Saya">
        <p className="fh" style={{ marginTop: 0 }}>
          Unduh salinan data pribadi Anda atau ajukan penghapusan data di halaman <Link href="/portal/privasi">Privasi &amp; Data</Link>. Untuk koreksi data, ubah formulir di atas atau hubungi admin lewat halaman <Link href="/portal/bantuan">Bantuan</Link>.
        </p>
        <Link className="kb2" href="/portal/privasi" style={{ display: "inline-block", marginTop: 12 }}>
          Buka Privasi &amp; Data
        </Link>
      </PortalCard>
    </>
  );
}
