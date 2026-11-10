import type { Metadata } from "next";
import CatalogHero from "@/components/catalog/CatalogHero";
import ContactForm from "@/components/forms/ContactForm";
import Icon from "@/components/ui/Icon";
import { SITE } from "@/data/site";
import { waLink } from "@/lib/utils";

export const metadata: Metadata = {
  title: `Kontak | ${SITE.name}`,
  description: `Hubungi PPTI - VMES Training Center: konsultasi training, pendaftaran, dan kerja sama. WhatsApp ${SITE.waDisplay}.`,
  alternates: { canonical: "/kontak" },
};

const MAP_QUERY = "Kawasan Industri Tunas Bizpark Blok D No 15 Batam Centre Batam";

/** /kontak — PRD §5.1: formulir konsultasi + kartu kontak + peta. */
export default function KontakPage() {
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
            <a className="kc" href={SITE.mapsUrl} rel="noopener" target="_blank">
              <span className="ki">
                <Icon name="pin" />
              </span>
              <div>
                <b>Kantor Pusat</b>
                <em>{SITE.company}</em>
                <small>{SITE.address}</small>
              </div>
            </a>
            <a className="kc wa" href={waLink()} target="_blank" rel="noopener">
              <span className="ki">
                <Icon name="phone" />
              </span>
              <div>
                <b>Telepon / WhatsApp</b>
                <em>{SITE.waDisplay}</em>
                <small>Chat langsung dengan tim kami</small>
              </div>
            </a>
            <div className="kc">
              <span className="ki">
                <Icon name="clock" />
              </span>
              <div>
                <b>Jam Operasional</b>
                <em>Senin – Jumat, 08.00 – 17.00 WIB</em>
                <small>Sabtu 08.00 – 13.00 WIB (kelas & ujian sesuai jadwal)</small>
              </div>
            </div>
            <div className="kmap">
              <iframe
                title="Lokasi PPTI - VMES Training Center"
                src={`https://www.google.com/maps?q=${encodeURIComponent(MAP_QUERY)}&output=embed`}
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
