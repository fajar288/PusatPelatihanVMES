import type { Metadata } from "next";
import CatalogHero from "@/components/catalog/CatalogHero";
import ContactForm from "@/components/forms/ContactForm";
import Icon from "@/components/ui/Icon";
import { getSiteSettings } from "@/lib/cms";
import { waLink } from "@/lib/utils";

export async function generateMetadata(): Promise<Metadata> {
  const site = await getSiteSettings();
  return {
    title: "Kontak",
    description: `Hubungi ${site.name}: konsultasi training, pendaftaran, dan kerja sama. WhatsApp ${site.waDisplay}.`,
    alternates: { canonical: "/kontak" },
  };
}

/** /kontak: formulir konsultasi + kartu kontak + peta. Alamat, nomor WhatsApp, dan peta dari pengaturan situs CMS. */
export default async function KontakPage() {
  const site = await getSiteSettings();
  return (
    <div>
      <CatalogHero
        eyebrow="Hubungi Kami"
        title={
          <>
            Konsultasikan Kebutuhan <em>Training Anda</em>
          </>
        }
        desc="Tim kami siap membantu memilih program, menyusun in-house training, atau menjawab pertanyaan seputar pendaftaran dan sertifikasi."
      />
      <div className="wrap">
        <div className="kgrid" style={{ marginTop: 36 }}>
          <div className="kbx" style={{ marginTop: 0 }}>
            <h2>Formulir Konsultasi</h2>
            <p className="sdt" style={{ marginTop: 8, color: "var(--mut)", fontWeight: 500 }}>
              Isi formulir di bawah, kami akan menghubungi Anda dalam 1 hari kerja.
            </p>
            <div style={{ marginTop: 22 }}>
              <ContactForm />
            </div>
          </div>

          <div className="kinfo">
            <a className="kc" href={site.mapsUrl} rel="noopener" target="_blank">
              <span className="ki">
                <Icon name="pin" />
              </span>
              <div>
                <b>Kantor Pusat</b>
                <em>{site.company}</em>
                <small>{site.address}</small>
              </div>
            </a>
            <a className="kc wa" href={waLink(site)} target="_blank" rel="noopener">
              <span className="ki">
                <Icon name="phone" />
              </span>
              <div>
                <b>Telepon / WhatsApp</b>
                <em>{site.waDisplay}</em>
                <small>Chat langsung dengan tim kami</small>
              </div>
            </a>
            <div className="kc">
              <span className="ki">
                <Icon name="clock" />
              </span>
              <div>
                <b>Jam Operasional</b>
                <em>Senin - Jumat, 08.00 - 17.00 WIB</em>
                <small>Sabtu 08.00 - 13.00 WIB (kelas & ujian sesuai jadwal)</small>
              </div>
            </div>
            <div className="kmap">
              <iframe
                title={`Lokasi ${site.name}`}
                src={`https://www.google.com/maps?q=${encodeURIComponent(site.address)}&output=embed`}
                loading="lazy"
                referrerPolicy="no-referrer-when-downgrade"
                allowFullScreen
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
