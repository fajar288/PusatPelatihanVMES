import type { IconName } from "@/components/ui/icons";

// Konten halaman /tentang (PRD §5.1: profil, visi-misi, fasilitas, trainer, layanan, kerja sama).
// Website lama tidak punya halaman ini; profil singkat & tagline dipakai dari beranda (data/home.ts).
// Teks di bawah adalah draf untuk ditinjau tim PPTI.

export const VISION = "Menjadi pusat pelatihan teknologi industri dan pengembangan SDM digital terdepan di Indonesia yang menghasilkan tenaga kerja kompeten, adaptif, dan siap menghadapi transformasi industri.";

export const MISSIONS = [
  "Menyelenggarakan pelatihan teknologi industri, otomasi, IT, dan manajemen yang relevan dengan kebutuhan dunia kerja.",
  "Mengutamakan pembelajaran berbasis praktik dengan fasilitas lab dan instruktur praktisi berpengalaman.",
  "Menjalin kemitraan dengan industri, principal teknologi, lembaga sertifikasi, dan institusi pendidikan.",
  "Menyediakan jalur sertifikasi internasional dan nasional yang diakui industri.",
  "Mendukung perusahaan dalam merancang program pengembangan kompetensi yang terukur.",
];

export const FACILITIES: { icon: IconName; title: string; desc: string }[] = [
  { icon: "network", title: "Lab Networking", desc: "Router dan switch Cisco serta MikroTik RouterBoard untuk setiap peserta, lengkap dengan rak dan patch panel." },
  { icon: "cpu", title: "Lab Otomasi Industri", desc: "Trainer kit PLC, HMI, SCADA, sensor, pneumatik, dan panel kendali motor untuk praktik sistem kendali." },
  { icon: "bot", title: "Lab Robotika & Mekatronika", desc: "Robot lengan industri, konveyor, dan stasiun mekatronika modular untuk simulasi lini produksi." },
  { icon: "laptop", title: "Ruang Kelas & Workstation", desc: "Ruang kelas ber-AC berkapasitas 12 sampai 20 peserta dengan workstation, proyektor, dan akses internet dedicated." },
  { icon: "shield", title: "Ruang Ujian Sertifikasi", desc: "Ruang ujian berstandar vendor dengan pengawasan untuk sertifikasi internasional." },
  { icon: "monitor", title: "Platform Belajar Daring", desc: "Portal peserta berisi materi, video rekaman, kuis, tugas, dan sertifikat digital yang dapat diverifikasi." },
];

export const TRAINERS: { name: string; role: string; certs: string; initials: string }[] = [
  { name: "Andi Pratama", role: "Lead Trainer Networking", certs: "CCNP Enterprise, CCNA, MTCNA", initials: "AP" },
  { name: "Budi Santoso", role: "Trainer MikroTik", certs: "MTCINE, MTCRE, MTCNA Certified Trainer", initials: "BS" },
  { name: "Rina Kusuma", role: "Trainer Cloud Computing", certs: "AWS Solutions Architect Associate, AWS Cloud Practitioner", initials: "RK" },
  { name: "Dimas Wijaya", role: "Trainer DevOps & Linux", certs: "CKA, RHCSA, GitLab Certified CI/CD Associate", initials: "DW" },
  { name: "Fajar Nugroho", role: "Trainer Cyber Security", certs: "CEH, CompTIA Security+", initials: "FN" },
  { name: "Sari Dewi, M.Kom", role: "Trainer Programming & Data", certs: "Python Institute PCAP, Microsoft Data Analyst", initials: "SD" },
];

export const SERVICES: { icon: IconName; title: string; desc: string; href: string }[] = [
  { icon: "users", title: "Public Training", desc: "Kelas reguler terjadwal yang terbuka untuk umum, individu, maupun utusan perusahaan.", href: "/jadwal" },
  { icon: "brief", title: "Corporate / In-House Training", desc: "Program disesuaikan dengan kebutuhan kompetensi perusahaan, dilaksanakan di lokasi Anda atau di lab kami.", href: "/kontak" },
  { icon: "cloud", title: "Online Class", desc: "Kelas daring interaktif melalui Zoom dengan lab virtual dan rekaman sesi.", href: "/kursus?mode=online" },
  { icon: "award", title: "Sertifikasi Internasional", desc: "Persiapan dan pelaksanaan ujian sertifikasi Cisco, MikroTik, AWS, dan vendor lainnya.", href: "/kursus" },
  { icon: "wrench", title: "Konsultasi & Assessment Kompetensi", desc: "Pemetaan kompetensi karyawan dan penyusunan roadmap pengembangan SDM.", href: "/kontak" },
  { icon: "grad", title: "Program Kampus & Sekolah", desc: "Magang bersertifikat, kunjungan industri, dan training for trainers untuk institusi pendidikan.", href: "/kontak" },
];

export const PARTNERSHIP_POINTS = [
  "Principal & vendor teknologi: Cisco, MikroTik, AWS, Pearson VUE, Certiport",
  "Perusahaan & BUMN: telekomunikasi, energi, manufaktur, dan perbankan",
  "Institusi pendidikan: politeknik, universitas, dan SMK teknik",
  "Instansi pemerintah: dinas, lembaga, dan BLK",
];
