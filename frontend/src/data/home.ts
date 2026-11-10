import type { IconName } from "@/components/ui/icons";

// ===== HERO =====
// Foto slider di website lama di-hotlink dari situs pihak ketiga (bukan milik PPTI).
// WAJIB diganti dengan foto PPTI sendiri sebelum go-live (lihat task.md §1.9).
export const HERO_SLIDES: string[] = [
  "https://the-european.eu/wp-content/uploads/GrayMatter_Robotics_new_headquarters-1536x1152.webp",
  "https://tudonghoa.caothang.edu.vn/images/images/Gioi%20thieu%20nganh/z7413330109909_f56c3a86bd125764f96b7acda2d0c33a.jpg",
  "https://www.tmic-ai.com/solutions/images/AI-smart-factory-solutions/tmic-smart-factory-implementation.webp",
  "https://asfanco.com/assets/img/engineering-labs-realistic/industrial-robotics-lab.webp",
];

export const HERO = {
  pill: "Pusat Pelatihan Teknologi Industri (VMES Training Center)",
  titleLine1: "Upgrade Your Skills.",
  titleLine2: "Shape the Future.",
  lead: "Pelatihan teknologi industri, otomasi, digitalisasi, dan pengembangan SDM yang dirancang untuk membantu individu, profesional, perusahaan, dan institusi meningkatkan kompetensi sesuai kebutuhan dunia kerja.",
  features: [
    { icon: "users", label: "Instruktur Praktisi Berpengalaman" },
    { icon: "shield", label: "Sertifikasi Internasional" },
    { icon: "cloud", label: "Online & Offline Fleksibel" },
    { icon: "laptop", label: "Lab Praktik Hands-on 24/7" },
  ] as { icon: IconName; label: string }[],
  glassStat: { icon: "grad" as IconName, title: "12.000+ Lulusan", sub: "Tersebar seluruh Indonesia" },
  officialPartners: [
    { name: "Cisco", logo: "/images/partners/cisco.png" },
    { name: "MikroTik", logo: "/images/partners/mikrotik.png" },
    { name: "Pearson VUE", logo: "/images/partners/pearson-vue.png" },
    { name: "Certiport", logo: "/images/partners/certiport.png" },
  ],
};

// ===== STATISTIK =====
export const STATS: { icon: IconName; n: number; suffix: string; label: string }[] = [
  { icon: "grad", n: 12000, suffix: "+", label: "Lulusan" },
  { icon: "users", n: 20, suffix: "+", label: "Instruktur Ahli" },
  { icon: "award", n: 10, suffix: "+ Tahun", label: "Pengalaman" },
  { icon: "trophy", n: 100, suffix: "+", label: "Jenis Training" },
  { icon: "shield", n: 300, suffix: "+", label: "Corporate Client" },
];

// ===== TENTANG KAMI =====
export const ABOUT = {
  eyebrow: "Profil Singkat",
  titlePrefix: "Tentang ",
  titleEm: "PPTI VMES Training Center",
  paragraphs: [
    "PPTI VMES Training Center adalah Pusat Pelatihan Teknologi Industri yang berfokus pada pengembangan kompetensi sumber daya manusia untuk menghadapi kebutuhan dunia kerja dan perkembangan teknologi. Program pelatihan mencakup teknologi informasi, otomasi industri, sistem kendali, robotika, teknologi manufaktur, digitalisasi bisnis, manajemen, dan pengembangan sumber daya manusia.",
    "Melalui pembelajaran yang terarah dan berorientasi pada penerapan, PPTI VMES Training Center mendukung individu, profesional, dan perusahaan dalam meningkatkan keterampilan teknis maupun manajerial. Materi pelatihan dirancang agar relevan dengan kebutuhan peserta, perkembangan industri, serta tantangan transformasi digital.",
    "PPTI VMES Training Center berkomitmen menjadi mitra pengembangan kompetensi yang mendorong pembelajaran berkelanjutan, peningkatan produktivitas, dan kesiapan tenaga kerja menghadapi perubahan industri.",
  ],
  tagline: "Mengembangkan Kompetensi, Mendorong Inovasi, Mendukung Kemajuan Industri.",
};

// ===== KATEGORI KOMPETENSI =====
export const CATEGORIES: { icon: IconName; title: string; desc: string; href: string }[] = [
  { icon: "cpu", title: "Industrial Automation & Control", desc: "PLC, HMI, SCADA, sensor, instrumentasi, dan sistem kendali industri.", href: "/kursus" },
  { icon: "bot", title: "Robotics & Mechatronics", desc: "Robotika industri, mekatronika, pneumatik, hidraulik, dan sistem mekanis-elektrik.", href: "/kursus" },
  { icon: "factory", title: "Smart Manufacturing & Industrial IoT", desc: "Konektivitas mesin, pengumpulan data, pemantauan proses, dan digitalisasi manufaktur.", href: "/kursus" },
  { icon: "chart", title: "IT, AI & Data Analytics", desc: "Pemrograman, database, kecerdasan buatan, analisis data, dan infrastruktur IT.", href: "/kursus" },
  { icon: "wrench", title: "Maintenance & Operational Excellence", desc: "Pemeliharaan, troubleshooting, Lean Manufacturing, Kaizen, dan produktivitas.", href: "/kursus" },
  { icon: "users", title: "Human Capital & Management", desc: "MSDM, leadership, KPI, manajemen proyek, komunikasi, dan manajemen perubahan.", href: "/kursus" },
  { icon: "zap", title: "Electrical, Instrumentation & Process Control", desc: "Kelistrikan industri, pengukuran, instrumentasi, dan pengendalian proses.", href: "/kursus" },
  { icon: "rocket", title: "Digital Transformation & Project Management", desc: "Strategi transformasi digital, otomasi proses bisnis, dan manajemen proyek teknologi.", href: "/kursus" },
];

// ===== MENGAPA PPTI =====
export const WHY = {
  eyebrow: "Mengapa PPTI",
  title: "Kami Mengembangkan Kompetensi yang Relevan dengan Kebutuhan Industri",
  sub: "PPTI (VMES Training Center) menghubungkan teknologi industri, keterampilan digital, dan pengembangan manusia melalui program yang dirancang untuk mendukung penerapan kompetensi di lingkungan kerja.",
  items: [
    { icon: "factory", title: "Fokus pada Teknologi Industri", desc: "Pelatihan diarahkan pada otomasi, sistem kendali, robotika, instrumentasi, dan teknologi manufaktur sesuai program serta fasilitas yang tersedia." },
    { icon: "wrench", title: "Pembelajaran Berbasis Praktik", desc: "Peserta diarahkan untuk memahami penerapan materi melalui latihan teknis, demonstrasi, simulasi, atau proyek sesuai karakteristik pelatihan." },
    { icon: "network", title: "Integrasi Teknologi dan Digitalisasi", desc: "Program tertentu menghubungkan sistem otomasi, pengumpulan data, Industrial IoT, dan analisis operasional agar peserta memahami hubungan antara teknologi dan proses kerja." },
    { icon: "users", title: "Pengembangan Kompetensi", desc: "Pelatihan juga mencakup kepemimpinan, komunikasi, kolaborasi, pemecahan masalah, produktivitas, dan manajemen perubahan." },
    { icon: "brief", title: "Pelatihan Korporasi yang Adaptif", desc: "Program dapat disesuaikan dengan kebutuhan kompetensi, teknologi, tantangan operasional, dan target pengembangan SDM perusahaan." },
    { icon: "award", title: "Hasil Belajar yang Terukur", desc: "Keberhasilan pelatihan dinilai berdasarkan tujuan pembelajaran melalui asesmen, praktik, proyek, dan tindak lanjut yang sesuai dengan jenis program." },
  ] as { icon: IconName; title: string; desc: string }[],
};

// ===== PARTNER & CLIENT (urutan sama dengan website lama) =====
export const PARTNERS: { name: string; logo: string }[] = [
  { name: "telkomsel", logo: "/images/partners/telkomsel.png" },
  { name: "Telkom Indonesia", logo: "/images/partners/telkom-indonesia.png" },
  { name: "Axis", logo: "/images/partners/axis.png" },
  { name: "ericsson", logo: "/images/partners/ericsson.png" },
  { name: "huawei", logo: "/images/partners/huawei.png" },
  { name: "indosat", logo: "/images/partners/indosat.png" },
  { name: "lapan", logo: "/images/partners/lapan.png" },
  { name: "bmkg", logo: "/images/partners/bmkg.png" },
  { name: "lp3i", logo: "/images/partners/lp3i.png" },
  { name: "mandiri", logo: "/images/partners/mandiri.png" },
  { name: "nec", logo: "/images/partners/nec.png" },
  { name: "zte", logo: "/images/partners/zte.png" },
  { name: "moratelindo", logo: "/images/partners/moratelindo.png" },
  { name: "Smartfren", logo: "/images/partners/smartfren.png" },
  { name: "xl axiata", logo: "/images/partners/xl-axiata.png" },
  { name: "PLN", logo: "/images/partners/pln.png" },
];

// ===== GALERI (hotlink pihak ketiga — ganti dengan foto PPTI) =====
export const GALLERY: { src: string; alt: string }[] = [
  { src: "https://merca.team/wp-content/uploads/2026/02/training-within-industry-1024x683.webp", alt: "Dokumentasi training PPTI" },
  { src: "https://livewireindia.com/blog/wp-content/uploads/2019/03/plc-scada-training-740x414.jpeg", alt: "Dokumentasi training PPTI" },
  { src: "https://agile4training.com/assets/images/courses/course_2283_1757248573.webp", alt: "Dokumentasi training PPTI" },
  { src: "https://www.arghajata.com/wp-content/uploads/2024/11/human-resources-management-software-computer-screen-helping-employee-to-manage-recruitment-payroll-staff-searching-kpi-319722581.webp", alt: "Dokumentasi training PPTI" },
  { src: "https://agile4training.com/assets/images/courses/course_2016_1743934487.webp", alt: "Dokumentasi training PPTI" },
  { src: "https://agile4training.com/assets/images/courses/course_1905_1744202959.webp", alt: "Dokumentasi training PPTI" },
];

// ===== CTA =====
export const CTA = {
  eyebrow: "Konsultasi Training",
  title: "Siap Mengembangkan Kompetensi untuk Industri Masa Depan?",
  desc: "Tingkatkan kemampuan teknis, digital, dan manajerial bersama VMES Training Center. Konsultasikan kebutuhan Anda untuk memilih program pelatihan, merancang pengembangan kompetensi karyawan, atau menyusun pelatihan khusus sesuai tantangan perusahaan.",
  points: [
    "Konsultasi gratis & tanpa komitmen",
    "Materi & jadwal sesuai kebutuhan tim",
    "Instruktur praktisi bersertifikasi",
    "Respon cepat via WhatsApp",
  ],
};
