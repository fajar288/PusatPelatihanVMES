import Link from "next/link";
import ProfileForm from "@/components/portal/ProfileForm";
import ProfilePhotoForm from "@/components/portal/ProfilePhotoForm";
import { MetaList, PortalCard } from "@/components/portal/ui";
import { requireUser } from "@/lib/auth";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal/profil: data member (PRD §5.6) + unduh salinan data pribadi (hak subjek data). */
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
          Unduh salinan data pribadi yang kami simpan untuk akun ini (profil, pendaftaran, pelatihan, invoice, dan sertifikat) dalam format JSON. Untuk meminta
          koreksi atau penghapusan data, hubungi admin lewat halaman <Link href="/portal/bantuan">Bantuan</Link>. Penjelasan lengkap ada di{" "}
          <Link href="/kebijakan-privasi">Kebijakan Privasi</Link>.
        </p>
        <a className="kb2" href="/portal/data-saya" download style={{ display: "inline-block", marginTop: 12 }}>
          Unduh Data Saya
        </a>
      </PortalCard>
    </>
  );
}
