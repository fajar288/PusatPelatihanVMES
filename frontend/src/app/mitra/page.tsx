import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import CatalogHero from "@/components/catalog/CatalogHero";
import { getPartners } from "@/lib/cms";
import type { PublicPartner } from "@/lib/cms-types";

export const metadata: Metadata = {
  title: "Mitra & Principal",
  description: "Principal teknologi, partner sertifikasi, serta perusahaan dan instansi yang mempercayakan pengembangan kompetensi SDM-nya kepada PPTI - VMES Training Center.",
  alternates: { canonical: "/mitra" },
};

const GROUPS: { type: string; id: string; eyebrow: string; title: string; desc: string }[] = [
  {
    type: "principal",
    id: "principal",
    eyebrow: "Principal",
    title: "Principal & Vendor Teknologi",
    desc: "Vendor teknologi yang materi dan jalur sertifikasinya kami ajarkan bersama instruktur bersertifikasi.",
  },
  {
    type: "certification",
    id: "sertifikasi",
    eyebrow: "Sertifikasi",
    title: "Partner Sertifikasi",
    desc: "Lembaga dan pusat ujian yang bekerja sama dalam penyelenggaraan ujian dan sertifikasi.",
  },
  {
    type: "client",
    id: "client",
    eyebrow: "Partner & Client",
    title: "Perusahaan & Instansi",
    desc: "Perusahaan dan instansi yang mempercayakan pengembangan kompetensi SDM-nya kepada kami.",
  },
];

function Logo({ p }: { p: PublicPartner }) {
  return p.w && p.h ? (
    <Image alt={p.name} src={p.logo} width={p.w} height={p.h} sizes="180px" style={{ width: "auto", height: "auto" }} />
  ) : (
    // logo dari CMS tanpa ukuran tersimpan
    <img alt={p.name} src={p.logo} loading="lazy" />
  );
}

/**
 * /mitra - menu "Partners" & "Principals" (PRD struktur menu publik). Website lama hanya punya section .ps di beranda;
 * halaman ini memakai hero .cath, judul .cn/.eb/.tt, dan kartu logo .lc yang sama, dikelompokkan per jenis mitra dari CMS.
 */
export default async function MitraPage() {
  const partners = await getPartners();
  const groups = GROUPS.map((g) => ({ ...g, items: partners.filter((p) => p.type === g.type) })).filter((g) => g.items.length > 0);

  return (
    <div>
      <CatalogHero
        eyebrow="Mitra Kami"
        title={
          <>
            Mitra & <em>Principal</em> PPTI - VMES
          </>
        }
        desc="Principal teknologi, partner sertifikasi, serta perusahaan dan instansi yang bekerja sama dengan PPTI - VMES Training Center."
      />

      {groups.map((g, i) => (
        <section className={i % 2 ? "sec alt" : "sec"} id={g.id} key={g.id}>
          <div className="wrap">
            <div className="cn">
              <span className="eb">{g.eyebrow}</span>
              <h2 className="tt">{g.title}</h2>
              <p className="sbt">{g.desc}</p>
            </div>
            <ul className="mgr" aria-label={g.title}>
              {g.items.map((p) => (
                <li key={p.slug ?? p.name} className={p.description || p.website ? "lc mgr-d" : "lc"}>
                  <span className="mgr-logo">
                    <Logo p={p} />
                  </span>
                  {(p.description || p.website) && (
                    <span className="mgr-tx">
                      <b>{p.name}</b>
                      {p.description && <span>{p.description}</span>}
                      {p.website && (
                        <a href={p.website} target="_blank" rel="noopener noreferrer">
                          Kunjungi situs
                        </a>
                      )}
                    </span>
                  )}
                </li>
              ))}
            </ul>
          </div>
        </section>
      ))}

      <section className={groups.length % 2 ? "sec alt" : "sec"}>
        <div className="wrap">
          <div className="cn">
            <span className="eb">Kerja Sama</span>
            <h2 className="tt">Ingin Bermitra dengan PPTI - VMES?</h2>
            <p className="sbt">Lihat bentuk kerja sama yang tersedia atau baca pengalaman peserta yang sudah mengikuti training kami.</p>
          </div>
          <div className="kbt mgr-cta">
            <Link className="kb1" href="/tentang#kerja-sama">
              Bentuk Kerja Sama
            </Link>
            <Link className="kb2" href="/testimoni">
              Testimoni Peserta
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}
