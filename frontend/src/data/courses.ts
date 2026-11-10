// Data kursus dummy — identik dengan konten contoh tema pusatpelatihan.id.
// Nanti diganti dengan GET /api/v1/packages dan /api/v1/packages/{slug} (Laravel).
export type CourseStatus = "ok" | "no";

export type CourseOverview = {
  intro: string[]; // paragraf pembuka
  learn: string[]; // "Yang Akan Dipelajari"
  audience: string[]; // "Untuk Siapa Training Ini"
  requirements: string[]; // "Persyaratan Peserta"
};

export type Course = {
  slug: string;
  title: string;
  category: string;
  vendor: string;
  desc: string;
  durationDays: number;
  mode: string; // "online" | "offline" | "hybrid" | "online / offline"
  status: CourseStatus;
  statusLabel: string;
  price: number;
  popular?: boolean;
  image?: string; // kosong → .noimg gradien hijau
  /** Unix time publikasi (data-d di website lama) — dipakai urutan "Terbaru". */
  publishedAt: number;
  // ---- detail (halaman /kursus/[slug]; website lama belum punya datanya) ----
  level: string;
  certificate: string;
  overview: CourseOverview;
};

export const COURSES: Course[] = [
  {
    slug: "cisco-ccna", title: "Cisco CCNA", category: "Networking", vendor: "Cisco",
    desc: "Training fundamental networking dan persiapan sertifikasi CCNA.",
    durationDays: 5, mode: "online / offline", status: "ok", statusLabel: "Tersedia", price: 7500000, popular: true, publishedAt: 1794268800,
    level: "Fundamental", certificate: "Cisco CCNA 200-301",
    overview: {
      intro: [
        "Cisco Certified Network Associate (CCNA) adalah sertifikasi networking paling diakui di industri. Training ini membekali peserta dengan pemahaman menyeluruh tentang dasar jaringan, IP connectivity, IP services, keamanan dasar, dan otomasi jaringan sesuai silabus ujian 200-301.",
        "Seluruh sesi dilaksanakan dengan pendekatan hands-on menggunakan perangkat Cisco dan Packet Tracer, sehingga peserta tidak hanya memahami teori tetapi juga terbiasa mengonfigurasi dan melakukan troubleshooting jaringan nyata.",
      ],
      learn: [
        "Network fundamentals: model OSI/TCP-IP, Ethernet, kabel, dan perangkat jaringan",
        "IPv4 dan IPv6 addressing serta subnetting",
        "Switching: VLAN, trunking, STP, EtherChannel",
        "Routing: static routing, OSPF single-area, default route",
        "IP services: DHCP, NAT, NTP, DNS, SNMP, syslog",
        "Security fundamentals: ACL, port security, AAA, VPN",
        "Wireless LAN dasar serta automation dan programmability (REST API, JSON, SDN)",
      ],
      audience: [
        "Network engineer / administrator pemula hingga menengah",
        "Staf IT yang ingin memperdalam infrastruktur jaringan",
        "Mahasiswa dan fresh graduate yang ingin memperoleh sertifikasi internasional",
      ],
      requirements: ["Pemahaman dasar penggunaan komputer dan internet", "Membawa laptop pribadi (minimal RAM 8 GB)", "Tidak memerlukan sertifikasi sebelumnya"],
    },
  },
  {
    slug: "aws-cloud-practitioner", title: "AWS Cloud Practitioner", category: "Cloud Computing", vendor: "AWS",
    desc: "Pelatihan cloud fundamental menggunakan Amazon Web Services.",
    durationDays: 3, mode: "hybrid", status: "ok", statusLabel: "Tersedia", price: 7000000, popular: true, publishedAt: 1794009600,
    level: "Fundamental", certificate: "AWS Certified Cloud Practitioner",
    overview: {
      intro: [
        "Training ini memperkenalkan konsep cloud computing dan layanan inti Amazon Web Services (AWS) sebagai bekal menghadapi ujian AWS Certified Cloud Practitioner. Peserta akan memahami nilai bisnis cloud, model harga, keamanan, dan arsitektur dasar di AWS.",
        "Setiap modul disertai lab langsung di konsol AWS sehingga peserta dapat mencoba layanan compute, storage, database, dan networking secara mandiri.",
      ],
      learn: [
        "Konsep cloud: elastisitas, high availability, shared responsibility model",
        "Layanan inti AWS: EC2, S3, RDS, VPC, IAM, Lambda",
        "Keamanan dan compliance di AWS",
        "Model harga, billing, dan cost optimization",
        "AWS Well-Architected Framework",
        "Strategi dan tips menghadapi ujian CLF-C02",
      ],
      audience: ["Profesional IT, sales, atau manajemen yang membutuhkan pemahaman cloud", "Developer dan sysadmin yang baru memulai perjalanan ke AWS", "Mahasiswa yang ingin memulai karier di bidang cloud"],
      requirements: ["Pemahaman dasar IT secara umum", "Akun AWS Free Tier (dipandu saat training)", "Membawa laptop pribadi"],
    },
  },
  {
    slug: "devops-engineering", title: "DevOps Engineering", category: "DevOps", vendor: "DevOps",
    desc: "Praktik CI/CD, container, dan otomasi infrastruktur.",
    durationDays: 5, mode: "online", status: "ok", statusLabel: "Tersedia", price: 8500000, publishedAt: 1793750400,
    level: "Intermediate", certificate: "Sertifikat Keikutsertaan PPTI - VMES",
    overview: {
      intro: [
        "DevOps Engineering membahas budaya, praktik, dan tooling yang menjembatani tim development dan operations. Peserta belajar membangun pipeline CI/CD, mengemas aplikasi dalam container, dan mengotomasi infrastruktur secara end-to-end.",
        "Training berorientasi proyek: peserta membangun pipeline lengkap dari commit kode hingga deployment ke cluster Kubernetes.",
      ],
      learn: [
        "Prinsip DevOps, version control dengan Git, dan branching strategy",
        "Continuous Integration & Delivery dengan GitLab CI / GitHub Actions",
        "Containerization dengan Docker dan Docker Compose",
        "Orkestrasi container dengan Kubernetes",
        "Infrastructure as Code dengan Terraform dan Ansible",
        "Monitoring dan logging: Prometheus, Grafana, ELK",
      ],
      audience: ["Software engineer yang ingin memahami deployment modern", "System administrator yang beralih ke peran DevOps/SRE", "Tim IT perusahaan yang ingin mengadopsi CI/CD"],
      requirements: ["Terbiasa dengan command line Linux", "Pemahaman dasar pemrograman atau scripting", "Laptop dengan minimal RAM 8 GB"],
    },
  },
  {
    slug: "mikrotik-mtcna", title: "MikroTik MTCNA", category: "Networking", vendor: "MikroTik",
    desc: "Konfigurasi router dan jaringan dengan MikroTik RouterOS.",
    durationDays: 3, mode: "offline", status: "ok", statusLabel: "Tersedia", price: 5500000, publishedAt: 1793491200,
    level: "Fundamental", certificate: "MikroTik Certified Network Associate (MTCNA)",
    overview: {
      intro: [
        "MTCNA adalah sertifikasi tingkat dasar MikroTik yang membekali peserta kemampuan mengonfigurasi, mengelola, dan melakukan troubleshooting router MikroTik RouterOS untuk kebutuhan jaringan kantor, ISP kecil, maupun hotspot.",
        "Training dilaksanakan secara offline di lab PPTI - VMES dengan perangkat RouterBoard asli untuk setiap peserta, dan diakhiri dengan ujian sertifikasi resmi MikroTik.",
      ],
      learn: [
        "Pengenalan RouterOS, Winbox, dan RouterBoard",
        "DHCP client/server, bridging, dan routing dasar",
        "Wireless: access point, station, dan keamanan WLAN",
        "Firewall filter, NAT, dan mangle",
        "QoS dengan Simple Queue dan Queue Tree",
        "Tunnel (PPPoE, PPTP, L2TP) dan hotspot",
      ],
      audience: ["Teknisi jaringan dan administrator kantor", "Pengelola RT/RW net, hotspot, dan ISP kecil", "Siswa SMK/mahasiswa jurusan jaringan"],
      requirements: ["Pemahaman dasar TCP/IP", "Membawa laptop pribadi dengan port Ethernet atau adapter", "Tidak memerlukan sertifikasi sebelumnya"],
    },
  },
  {
    slug: "cyber-security-foundation", title: "Cyber Security Foundation", category: "Cyber Security", vendor: "Cyber Security",
    desc: "Dasar keamanan informasi dan pertahanan jaringan.",
    durationDays: 4, mode: "online", status: "no", statusLabel: "Segera dibuka", price: 6500000, publishedAt: 1793232000,
    level: "Fundamental", certificate: "Sertifikat Keikutsertaan PPTI - VMES",
    overview: {
      intro: [
        "Cyber Security Foundation memberikan fondasi keamanan informasi: konsep CIA triad, ancaman dan kerentanan, pertahanan jaringan, hingga respons insiden. Peserta memahami cara penyerang bekerja agar dapat membangun pertahanan yang tepat.",
        "Lab dilakukan di lingkungan virtual yang aman, mencakup analisis trafik, hardening sistem, dan simulasi serangan dasar.",
      ],
      learn: [
        "Prinsip keamanan informasi dan manajemen risiko",
        "Jenis ancaman: malware, phishing, social engineering, DDoS",
        "Keamanan jaringan: firewall, IDS/IPS, VPN, segmentasi",
        "Hardening sistem operasi dan manajemen patch",
        "Kriptografi dasar dan manajemen identitas",
        "Pengenalan security operations dan incident response",
      ],
      audience: ["Staf IT yang bertanggung jawab atas keamanan sistem", "Fresh graduate yang ingin memulai karier cyber security", "Manajer yang membutuhkan pemahaman risiko siber"],
      requirements: ["Pemahaman dasar jaringan dan sistem operasi", "Laptop dengan minimal RAM 8 GB untuk menjalankan VM"],
    },
  },
  {
    slug: "python-programming", title: "Python Programming", category: "Programming", vendor: "Programming",
    desc: "Dasar pemrograman Python untuk otomasi dan analisis data.",
    durationDays: 4, mode: "hybrid", status: "ok", statusLabel: "Tersedia", price: 4500000, publishedAt: 1792972800,
    level: "Fundamental", certificate: "Sertifikat Keikutsertaan PPTI - VMES",
    overview: {
      intro: [
        "Python adalah bahasa pemrograman serbaguna yang paling banyak dipakai untuk otomasi, analisis data, dan kecerdasan buatan. Training ini dirancang bagi pemula tanpa latar belakang pemrograman dan berfokus pada praktik langsung.",
        "Di akhir training peserta mampu menulis skrip otomasi pekerjaan rutin dan mengolah data sederhana dengan library populer.",
      ],
      learn: [
        "Sintaks dasar: variabel, tipe data, kontrol alur, fungsi",
        "Struktur data: list, dict, set, tuple, dan comprehension",
        "Modul, package, dan virtual environment",
        "File handling, exception, dan otomasi tugas (os, pathlib, requests)",
        "Pengolahan data dengan pandas dan visualisasi dengan matplotlib",
        "Mini project: otomasi laporan dari file Excel/CSV",
      ],
      audience: ["Pemula yang ingin belajar pemrograman dari nol", "Staf administrasi/analis yang ingin mengotomasi pekerjaan", "Engineer yang membutuhkan Python untuk otomasi industri"],
      requirements: ["Terbiasa menggunakan komputer", "Membawa laptop pribadi (Windows/macOS/Linux)"],
    },
  },
];

export function getCourse(slug: string): Course | undefined {
  return COURSES.find((c) => c.slug === slug);
}

/** Kursus terkait: kategori sama dulu, lalu sisanya; maksimal `n`. */
export function getRelatedCourses(course: Course, n = 3): Course[] {
  const others = COURSES.filter((c) => c.slug !== course.slug);
  const same = others.filter((c) => c.category === course.category);
  const rest = others.filter((c) => c.category !== course.category);
  return [...same, ...rest].slice(0, n);
}

export const COURSE_CATEGORIES = [...new Set(COURSES.map((c) => c.category))].sort();
export const COURSE_MODES = [...new Set(COURSES.map((c) => c.mode))].sort();
