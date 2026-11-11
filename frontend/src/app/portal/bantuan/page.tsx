import Link from "next/link";
import { PortalCard } from "@/components/portal/ui";
import { SITE } from "@/data/site";
import { requireUser } from "@/lib/auth";
import { waLink } from "@/lib/utils";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal/bantuan — Support: kontak admin, tautan panduan. */
export default async function PortalSupportPage() {
  const { user } = await requireUser("/portal/bantuan");
  return (
    <PortalCard title="Bantuan">
      <p className="fh" style={{ marginTop: 6 }}>
        Tim admin melayani pada hari kerja pukul 08.00 sampai 17.00 WIB. Sertakan nomor member Anda ({user.member?.memberNumber}) agar lebih cepat diproses.
      </p>
      <div className="kbt" style={{ gridTemplateColumns: "1fr 1fr", marginTop: 20 }}>
        <a className="kb1 wa" href={waLink(`Halo, saya member ${user.member?.memberNumber ?? ""} (${user.name}) membutuhkan bantuan.`)} target="_blank" rel="noopener">
          Chat WhatsApp {SITE.waDisplay}
        </a>
        <Link className="kb2" href="/kontak">
          Formulir Konsultasi
        </Link>
      </div>
      <ul className="sp" style={{ marginTop: 24 }}>
        <li>
          <span>Cara pendaftaran</span>
          <b>
            <Link href="/cara-pendaftaran">Baca panduan</Link>
          </b>
        </li>
        <li>
          <span>Pertanyaan umum</span>
          <b>
            <Link href="/faq">FAQ</Link>
          </b>
        </li>
        <li>
          <span>Kebijakan</span>
          <b>
            <span className="spv">
              <Link href="/syarat-ketentuan">Syarat &amp; Ketentuan</Link>
              <Link href="/kebijakan-privasi">Kebijakan Privasi</Link>
            </span>
          </b>
        </li>
      </ul>
    </PortalCard>
  );
}
