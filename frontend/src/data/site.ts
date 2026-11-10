// Identitas situs — diambil apa adanya dari pusatpelatihan.id
export const SITE = {
  name: "PPTI – VMES Training Center",
  shortName: "VMES Training Center",
  company: "PT. PPTI - VMES Training Center",
  description:
    "Pelatihan teknologi industri, otomasi, digitalisasi, dan pengembangan SDM yang dirancang untuk membantu individu, profesional, perusahaan, dan institusi meningkatkan kompetensi sesuai kebutuhan dunia kerja.",
  logo: "/images/logo.jpeg",
  waNumber: "6285121251285",
  waDisplay: "0851-2125-1285",
  waDefaultText: "Halo, saya ingin info training di PPTI - VMES",
  address:
    "Batam Centre, Kawasan Industri Tunas Bizpark Blok D No 15 Lantai 2, Belian, Kec. Batam Kota, Kota Batam, Kepulauan Riau 29464",
  mapsUrl:
    "https://www.google.com/maps/search/?api=1&query=Kawasan+Industri+Tunas+Bizpark+Blok+D+No+15+Batam+Centre+Batam",
  youtubeProfileId: "IClaATbg--A",
  footerDescription:
    "PT. PPTI - VMES Training Center adalah penyedia pelatihan teknologi industri, IT, dan SDM digital. Kami membantu individu, profesional, perusahaan, dan instansi meningkatkan kompetensi untuk menghadapi tantangan dunia kerja dan industri modern.",
  tagline:
    "Dibangun untuk meningkatkan kompetensi teknologi, industri, dan SDM digital Indonesia.",
};

export type NavItem = { label: string; href: string; external?: boolean; cta?: boolean };

export const NAV: NavItem[] = [
  { label: "Beranda", href: "/" },
  { label: "Kursus", href: "/kursus" },
  { label: "Jadwal", href: "/jadwal" },
  { label: "Tutorial", href: "/tutorial" },
  { label: "Berita", href: "/berita" },
  { label: "Kegiatan", href: "/kegiatan" },
];

export const FOOTER_COLUMNS: { title: string; links: NavItem[] }[] = [
  {
    title: "Layanan",
    links: [
      { label: "Public Training", href: "/kursus" },
      { label: "Corporate Training", href: "/kontak" },
      { label: "Online Class", href: "/kursus" },
      { label: "Sertifikasi Internasional", href: "/kursus" },
    ],
  },
  {
    title: "Perusahaan",
    links: [
      { label: "Tentang Kami", href: "/tentang" },
      { label: "Tutorial", href: "/tutorial" },
      { label: "Jadwal Training", href: "/jadwal" },
      { label: "Kontak", href: "/kontak" },
    ],
  },
  {
    title: "Bantuan",
    links: [
      { label: "FAQ", href: "/faq" },
      { label: "Cara Pendaftaran", href: "/cara-pendaftaran" },
      { label: "Kebijakan Privasi", href: "/kebijakan-privasi" },
      { label: "Syarat & Ketentuan", href: "/syarat-ketentuan" },
    ],
  },
];

// Media sosial: di website lama kosong. Isi saat link resmi tersedia.
export const SOCIALS: { icon: "yt" | "fb" | "tw" | "ig" | "in"; href: string; label: string }[] = [];
