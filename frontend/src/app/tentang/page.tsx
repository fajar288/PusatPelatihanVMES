import type { Metadata } from "next";
import Link from "next/link";
import WhyCard from "@/components/cards/WhyCard";
import CatalogHero from "@/components/catalog/CatalogHero";
import About from "@/components/home/About";
import CtaBanner from "@/components/home/CtaBanner";
import Partners from "@/components/home/Partners";
import Icon from "@/components/ui/Icon";
import { FACILITIES, MISSIONS, PARTNERSHIP_POINTS, SERVICES, TRAINERS, VISION } from "@/data/about";
import { SITE } from "@/data/site";
import { waLink } from "@/lib/utils";

export const metadata: Metadata = {
  title: `Tentang Kami | ${SITE.name}`,
  description: SITE.footerDescription,
  alternates: { canonical: "/tentang" },
};

/**
 * /tentang — PRD §5.1: profil, visi-misi, fasilitas, trainer, layanan, kerja sama.
 * Website lama tidak punya halaman ini; dibangun dari section beranda (.tk, .wg/.wc, .ps, .cs) + gaya .kbx.
 */
export default function TentangPage() {
  return (
    <div>
      <CatalogHero
        eyebrow="Profil Perusahaan"
        title={
          <>
            Tentang <em>PPTI VMES Training Center</em>
          </>
        }
        desc={SITE.tagline}
      />

      <About />

      <section className="sec alt" id="visi-misi">
        <div className="wrap">
          <div className="cn">
            <span className="eb">Visi &amp; Misi</span>
            <h2 className="tt">Arah dan Komitmen Kami</h2>
          </div>
          <div className="vm">
            <div className="kbx">
              <h3>Visi</h3>
              <p>{VISION}</p>
            </div>
            <div className="kbx">
              <h3>Misi</h3>
              <ol>
                {MISSIONS.map((m) => (
                  <li key={m}>{m}</li>
                ))}
              </ol>
            </div>
          </div>
        </div>
      </section>

      <section className="sec" id="fasilitas">
        <div className="wrap">
          <div className="cn">
            <span className="eb">Fasilitas</span>
            <h2 className="tt">Lab Praktik dan Ruang Kelas Berstandar Industri</h2>
            <p className="sbt">Seluruh program dirancang hands-on, sehingga fasilitas lab menjadi inti pengalaman belajar di PPTI - VMES.</p>
          </div>
          <div className="wg">
            {FACILITIES.map((f, i) => (
              <WhyCard key={f.title} {...f} index={i + 1} />
            ))}
          </div>
        </div>
      </section>

      <section className="sec alt" id="trainer">
        <div className="wrap">
          <div className="cn">
            <span className="eb">Trainer</span>
            <h2 className="tt">Instruktur Praktisi Bersertifikasi</h2>
            <p className="sbt">Instruktur kami adalah praktisi aktif di industri yang memegang sertifikasi vendor dan pengalaman mengajar bertahun-tahun.</p>
          </div>
          <div className="trg">
            {TRAINERS.map((t) => (
              <div className="trc" key={t.name}>
                <span className="av" aria-hidden="true">
                  {t.initials}
                </span>
                <div>
                  <b>{t.name}</b>
                  <em>{t.role}</em>
                  <small>{t.certs}</small>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="sec" id="layanan">
        <div className="wrap">
          <div className="cn">
            <span className="eb">Layanan</span>
            <h2 className="tt">Pilihan Program Sesuai Kebutuhan Anda</h2>
          </div>
          <div className="wg">
            {SERVICES.map((s) => (
              <div className="wc" key={s.title}>
                <div className="wi">
                  <Icon name={s.icon} />
                </div>
                <div className="wh">
                  <h3>{s.title}</h3>
                </div>
                <p>{s.desc}</p>
                <Link className="lk" href={s.href}>
                  Selengkapnya <Icon name="arr" />
                </Link>
              </div>
            ))}
          </div>
        </div>
      </section>

      <Partners />

      <section className="sec alt" id="kerja-sama">
        <div className="wrap">
          <div className="cn">
            <span className="eb">Kerja Sama</span>
            <h2 className="tt">Bermitra dengan PPTI - VMES</h2>
            <p className="sbt">Kami terbuka untuk kerja sama pengembangan kompetensi dengan perusahaan, principal teknologi, institusi pendidikan, dan instansi pemerintah.</p>
          </div>
          <div className="kbx" style={{ maxWidth: 820, margin: "36px auto 0" }}>
            <h2 style={{ fontSize: 22 }}>Bentuk kerja sama</h2>
            <div className="kbl">
              {PARTNERSHIP_POINTS.map((p) => (
                <div key={p}>
                  <span className="ib">
                    <Icon name="check" />
                  </span>
                  {p}
                </div>
              ))}
            </div>
            <div className="kbt" style={{ gridTemplateColumns: "1fr 1fr" }}>
              <a className="kb1 wa" href={waLink("Halo, saya ingin menjajaki kerja sama dengan PPTI - VMES")} target="_blank" rel="noopener">
                Ajukan Kerja Sama
              </a>
              <Link className="kb2" href="/kontak">
                Hubungi Kami
              </Link>
            </div>
          </div>
        </div>
      </section>

      <CtaBanner />
    </div>
  );
}
