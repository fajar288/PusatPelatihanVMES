// Postingan (tutorial, berita, kegiatan, dokumentasi) — dummy. Website lama hanya punya 1 post
// bawaan WordPress ("Hello world!") plus konten contoh tema; judul & tanggal tutorial diambil apa adanya.
// Nanti diganti GET /api/v1/posts?category= dan /api/v1/posts/{slug} (Laravel).

export type PostCategory = "Tutorial" | "Berita" | "Kegiatan" | "Dokumentasi";

export type PostBlock =
  | { type: "p"; text: string }
  | { type: "h2"; text: string }
  | { type: "h3"; text: string }
  | { type: "ul"; items: string[] }
  | { type: "ol"; items: string[] }
  | { type: "pre"; code: string }
  | { type: "img"; src: string; alt: string; caption?: string };

export type Article = {
  slug: string;
  title: string;
  excerpt: string;
  category: PostCategory;
  dateLabel: string; // "10 Nov 2026" (format website lama)
  publishedAt: number; // unix time (data-d di website lama) — urutan "Terbaru"
  image?: string;
  author?: string;
  tags?: string[];
  body: PostBlock[];
};

export const POST_CATEGORIES: { slug: string; category: PostCategory; title: string; desc: string }[] = [
  { slug: "tutorial", category: "Tutorial", title: "Tutorial", desc: "Artikel, tutorial, berita, dan kegiatan terbaru." },
  { slug: "berita", category: "Berita", title: "Berita", desc: "Artikel, tutorial, berita, dan kegiatan terbaru." },
  { slug: "kegiatan", category: "Kegiatan", title: "Kegiatan", desc: "Artikel, tutorial, berita, dan kegiatan terbaru." },
  { slug: "dokumentasi", category: "Dokumentasi", title: "Dokumentasi", desc: "Dokumentasi kelas, sesi praktik lab, dan training korporat PPTI - VMES Training Center." },
];

const SAMPLE = "Contoh konten. Tulis postingan berlabel Tutorial untuk menggantikan tampilan contoh ini.";

export const ARTICLES: Article[] = [
  // ===== TUTORIAL (6 judul & tanggal persis seperti konten contoh tema) =====
  {
    slug: "konfigurasi-eigrp", title: "Konfigurasi Enhanced Interior Gateway Routing Protocol (EIGRP)", excerpt: SAMPLE,
    category: "Tutorial", dateLabel: "10 Nov 2026", publishedAt: 1794268800, author: "Tim Instruktur PPTI", tags: ["Cisco", "Routing", "CCNA"],
    body: [
      { type: "p", text: "EIGRP (Enhanced Interior Gateway Routing Protocol) adalah protokol routing dinamis milik Cisco yang menggabungkan keunggulan distance-vector dan link-state. EIGRP cepat konvergen, hemat bandwidth, dan mudah dikonfigurasi sehingga banyak dipakai di jaringan enterprise." },
      { type: "h2", text: "Topologi Lab" },
      { type: "p", text: "Gunakan dua router (R1 dan R2) yang terhubung lewat link serial atau GigabitEthernet. Masing-masing router memiliki satu jaringan LAN di belakangnya." },
      { type: "h2", text: "Langkah Konfigurasi" },
      { type: "ol", items: ["Masuk ke mode konfigurasi global dengan perintah configure terminal.", "Aktifkan proses EIGRP dengan router eigrp 100 (nomor AS harus sama di semua router).", "Umumkan jaringan yang terhubung dengan perintah network beserta wildcard mask.", "Matikan auto-summary agar subnet tidak diringkas otomatis.", "Verifikasi dengan show ip eigrp neighbors dan show ip route eigrp."] },
      { type: "pre", code: "R1(config)# router eigrp 100\nR1(config-router)# network 192.168.1.0 0.0.0.255\nR1(config-router)# network 10.0.0.0 0.0.0.3\nR1(config-router)# no auto-summary" },
      { type: "h2", text: "Verifikasi" },
      { type: "p", text: "Jika neighbor adjacency terbentuk, tabel routing akan menampilkan rute dengan kode D (EIGRP). Lakukan ping antar LAN untuk memastikan konektivitas end-to-end." },
      { type: "p", text: "Materi ini merupakan bagian dari training Cisco CCNA di PPTI - VMES Training Center." },
    ],
  },
  {
    slug: "konfigurasi-vlan-trunking", title: "Konfigurasi VLAN dan Trunking pada Switch", excerpt: SAMPLE,
    category: "Tutorial", dateLabel: "7 Nov 2026", publishedAt: 1794009600, author: "Tim Instruktur PPTI", tags: ["Cisco", "Switching", "VLAN"],
    body: [
      { type: "p", text: "VLAN (Virtual LAN) memisahkan satu switch fisik menjadi beberapa jaringan logis, sedangkan trunking memungkinkan beberapa VLAN melewati satu link antar switch menggunakan tagging IEEE 802.1Q." },
      { type: "h2", text: "Membuat VLAN" },
      { type: "pre", code: "SW1(config)# vlan 10\nSW1(config-vlan)# name STAFF\nSW1(config)# vlan 20\nSW1(config-vlan)# name GUEST" },
      { type: "h2", text: "Menetapkan Port Akses" },
      { type: "pre", code: "SW1(config)# interface range fa0/1 - 12\nSW1(config-if-range)# switchport mode access\nSW1(config-if-range)# switchport access vlan 10" },
      { type: "h2", text: "Mengaktifkan Trunk" },
      { type: "pre", code: "SW1(config)# interface gi0/1\nSW1(config-if)# switchport mode trunk\nSW1(config-if)# switchport trunk allowed vlan 10,20" },
      { type: "p", text: "Verifikasi dengan show vlan brief dan show interfaces trunk. Pastikan native VLAN sama di kedua ujung trunk untuk menghindari peringatan CDP." },
    ],
  },
  {
    slug: "dasar-docker-pemula", title: "Dasar Docker untuk Pemula", excerpt: SAMPLE,
    category: "Tutorial", dateLabel: "4 Nov 2026", publishedAt: 1793750400, author: "Tim Instruktur PPTI", tags: ["DevOps", "Docker"],
    body: [
      { type: "p", text: "Docker mengemas aplikasi beserta seluruh dependensinya ke dalam container yang ringan dan portabel. Dengan Docker, aplikasi berjalan sama persis di laptop developer, server staging, maupun produksi." },
      { type: "h2", text: "Konsep Utama" },
      { type: "ul", items: ["Image: template read-only berisi aplikasi dan dependensinya.", "Container: instance image yang sedang berjalan.", "Dockerfile: resep untuk membangun image.", "Registry: tempat menyimpan dan membagikan image (Docker Hub, GitLab Registry)."] },
      { type: "h2", text: "Perintah Dasar" },
      { type: "pre", code: "docker pull nginx:alpine\ndocker run -d -p 8080:80 --name web nginx:alpine\ndocker ps\ndocker logs web\ndocker stop web && docker rm web" },
      { type: "h2", text: "Dockerfile Sederhana" },
      { type: "pre", code: "FROM python:3.12-slim\nWORKDIR /app\nCOPY requirements.txt .\nRUN pip install -r requirements.txt\nCOPY . .\nCMD [\"python\", \"app.py\"]" },
      { type: "p", text: "Pelajari lebih dalam tentang container, Compose, dan Kubernetes di training DevOps Engineering." },
    ],
  },
  {
    slug: "firewall-rule-mikrotik", title: "Membuat Firewall Rule di MikroTik", excerpt: SAMPLE,
    category: "Tutorial", dateLabel: "1 Nov 2026", publishedAt: 1793491200, author: "Tim Instruktur PPTI", tags: ["MikroTik", "Firewall"],
    body: [
      { type: "p", text: "Firewall di RouterOS bekerja berdasarkan chain (input, forward, output) dan dieksekusi berurutan dari atas ke bawah. Rule yang paling spesifik dan paling sering cocok sebaiknya diletakkan di atas." },
      { type: "h2", text: "Rule Dasar Melindungi Router" },
      { type: "pre", code: "/ip firewall filter\nadd chain=input connection-state=established,related action=accept\nadd chain=input connection-state=invalid action=drop\nadd chain=input in-interface=ether1-WAN protocol=icmp action=accept\nadd chain=input in-interface=ether1-WAN action=drop" },
      { type: "h2", text: "Membatasi Akses dari LAN" },
      { type: "pre", code: "add chain=forward src-address=192.168.88.0/24 dst-port=23 protocol=tcp action=drop comment=\"Blok telnet keluar\"" },
      { type: "p", text: "Selalu uji rule dari jaringan lain sebelum mengaktifkan drop di chain input agar tidak terkunci dari router sendiri. Gunakan Safe Mode di Winbox saat bereksperimen." },
    ],
  },
  {
    slug: "pengenalan-cicd-pipeline", title: "Pengenalan CI/CD Pipeline", excerpt: SAMPLE,
    category: "Tutorial", dateLabel: "29 Okt 2026", publishedAt: 1793232000, author: "Tim Instruktur PPTI", tags: ["DevOps", "CI/CD"],
    body: [
      { type: "p", text: "Continuous Integration (CI) memastikan setiap perubahan kode diuji otomatis, sedangkan Continuous Delivery/Deployment (CD) mengantarkan perubahan tersebut ke lingkungan staging atau produksi tanpa langkah manual yang rawan kesalahan." },
      { type: "h2", text: "Tahapan Pipeline Umum" },
      { type: "ol", items: ["Build: kompilasi kode dan instalasi dependensi.", "Test: unit test, integration test, dan analisis statis.", "Package: membangun artefak atau image Docker.", "Deploy: merilis ke staging, lalu produksi setelah approval."] },
      { type: "h2", text: "Contoh GitHub Actions" },
      { type: "pre", code: "name: ci\non: [push]\njobs:\n  test:\n    runs-on: ubuntu-latest\n    steps:\n      - uses: actions/checkout@v4\n      - run: npm ci\n      - run: npm test" },
      { type: "p", text: "Pipeline yang baik bersifat cepat, deterministik, dan memberikan umpan balik jelas ke developer." },
    ],
  },
  {
    slug: "subnetting-ipv4", title: "Subnetting IPv4 Langkah demi Langkah", excerpt: SAMPLE,
    category: "Tutorial", dateLabel: "26 Okt 2026", publishedAt: 1792972800, author: "Tim Instruktur PPTI", tags: ["Networking", "IPv4"],
    body: [
      { type: "p", text: "Subnetting membagi satu blok alamat IP menjadi beberapa jaringan yang lebih kecil agar penggunaan alamat efisien dan trafik broadcast terkendali." },
      { type: "h2", text: "Contoh: 192.168.10.0/24 dibagi menjadi 4 subnet" },
      { type: "ol", items: ["Butuh 4 subnet → pinjam 2 bit host (2^2 = 4). Prefix baru /26, mask 255.255.255.192.", "Ukuran blok = 256 - 192 = 64. Subnet: .0, .64, .128, .192.", "Setiap subnet punya 62 host yang bisa dipakai (64 - 2).", "Alamat broadcast subnet pertama: 192.168.10.63; host valid .1 sampai .62."] },
      { type: "h2", text: "Tips Cepat" },
      { type: "ul", items: ["Hafalkan kelipatan 2: 128, 64, 32, 16, 8, 4, 2.", "Ukuran blok selalu 256 dikurangi oktet mask yang 'menarik'.", "VLSM: urutkan kebutuhan host dari terbesar ke terkecil."] },
    ],
  },

  // ===== BERITA =====
  {
    slug: "ppti-mitra-resmi-pearson-vue", title: "PPTI - VMES Resmi Menjadi Authorized Test Center Pearson VUE", excerpt: "Peserta kini dapat mengikuti ujian sertifikasi internasional Cisco, AWS, dan CompTIA langsung di lab PPTI - VMES Training Center Batam.",
    category: "Berita", dateLabel: "8 Nov 2026", publishedAt: 1794096000, author: "Humas PPTI", tags: ["Sertifikasi", "Pearson VUE"],
    body: [
      { type: "p", text: "Batam — PPTI - VMES Training Center resmi ditunjuk sebagai Pearson VUE Authorized Test Center. Dengan status ini, peserta training tidak perlu lagi menempuh perjalanan ke kota lain untuk mengikuti ujian sertifikasi internasional." },
      { type: "p", text: "Ujian yang tersedia mencakup Cisco (CCNA, CCNP), AWS Certified, CompTIA, dan vendor lain yang bermitra dengan Pearson VUE. Pendaftaran ujian dapat dilakukan melalui tim admin PPTI atau langsung di portal Pearson VUE dengan memilih lokasi Batam." },
      { type: "h2", text: "Fasilitas Ruang Ujian" },
      { type: "ul", items: ["8 workstation dengan pengawasan CCTV sesuai standar Pearson VUE", "Ruang tunggu dan loker peserta", "Jadwal ujian Senin sampai Sabtu"] },
      { type: "p", text: "Informasi jadwal ujian dan voucher dapat ditanyakan melalui WhatsApp resmi PPTI - VMES." },
    ],
  },
  {
    slug: "jadwal-training-semester-1-2027", title: "Jadwal Training Semester I 2027 Telah Dibuka", excerpt: "Kelas reguler Cisco CCNA, MikroTik MTCNA, AWS, DevOps, Cyber Security, dan Python dibuka untuk periode Januari sampai Juni 2027.",
    category: "Berita", dateLabel: "3 Nov 2026", publishedAt: 1793664000, author: "Humas PPTI", tags: ["Jadwal", "Pendaftaran"],
    body: [
      { type: "p", text: "PPTI - VMES Training Center membuka pendaftaran kelas reguler semester I 2027. Kuota tiap kelas dibatasi 12 sampai 16 peserta agar sesi praktik berjalan optimal." },
      { type: "h2", text: "Promo Pendaftaran Awal" },
      { type: "ul", items: ["Diskon 10% untuk pendaftaran sebelum 31 Desember 2026", "Diskon tambahan 5% untuk pendaftaran kolektif minimal 3 orang dari instansi yang sama", "Gratis voucher ujian untuk kelas MikroTik MTCNA"] },
      { type: "p", text: "Lihat jadwal lengkap di halaman Jadwal Training dan klik Daftar pada batch yang diinginkan." },
    ],
  },
  {
    slug: "kerja-sama-politeknik-batam", title: "Kerja Sama PPTI - VMES dengan Politeknik Negeri Batam untuk Program Magang Bersertifikat", excerpt: "Mahasiswa semester akhir dapat mengikuti program magang sekaligus memperoleh sertifikasi industri di lab PPTI - VMES.",
    category: "Berita", dateLabel: "20 Okt 2026", publishedAt: 1792454400, author: "Humas PPTI", tags: ["Kerja Sama", "Kampus"],
    body: [
      { type: "p", text: "Penandatanganan nota kesepahaman dilakukan di kampus Politeknik Negeri Batam. Program ini memadukan magang di industri mitra PPTI dengan pelatihan intensif Industrial Automation dan Networking." },
      { type: "p", text: "Setiap angkatan akan menerima 20 mahasiswa. Lulusan program memperoleh sertifikat keikutsertaan PPTI dan kesempatan mengikuti ujian sertifikasi vendor dengan subsidi biaya." },
    ],
  },

  // ===== KEGIATAN =====
  {
    slug: "inhouse-training-plc-pln-batam", title: "In-House Training PLC & SCADA untuk Tim Teknik PLN Batam", excerpt: "Tiga hari pelatihan intensif pemrograman PLC, HMI, dan integrasi SCADA bagi 16 teknisi PLN Batam.",
    category: "Kegiatan", dateLabel: "6 Nov 2026", publishedAt: 1793923200, author: "Tim Training PPTI", tags: ["In-House", "PLC", "SCADA"],
    body: [
      { type: "p", text: "PPTI - VMES Training Center melaksanakan in-house training Industrial Automation untuk tim teknik PLN Batam selama tiga hari. Materi mencakup dasar PLC, pemrograman ladder, konfigurasi HMI, dan integrasi SCADA." },
      { type: "p", text: "Sesi praktik menggunakan trainer kit PLC dan simulasi proses industri sehingga peserta langsung dapat menerapkan materi pada kasus nyata di lapangan." },
      { type: "h2", text: "Hasil Pelatihan" },
      { type: "ul", items: ["16 peserta menyelesaikan seluruh modul dan asesmen praktik", "Rata-rata nilai post-test naik 38% dibanding pre-test", "Seluruh peserta memperoleh sertifikat keikutsertaan"] },
    ],
  },
  {
    slug: "workshop-cyber-security-awareness", title: "Workshop Cyber Security Awareness untuk UMKM Kota Batam", excerpt: "Kolaborasi dengan Dinas Kominfo Kota Batam: 60 pelaku UMKM belajar mengamankan akun, data pelanggan, dan transaksi digital.",
    category: "Kegiatan", dateLabel: "25 Okt 2026", publishedAt: 1792886400, author: "Tim Training PPTI", tags: ["Workshop", "Cyber Security"],
    body: [
      { type: "p", text: "Workshop setengah hari ini membahas ancaman siber yang paling sering menimpa usaha kecil: phishing, pembajakan akun media sosial, dan penipuan transaksi." },
      { type: "p", text: "Peserta mempraktikkan pengaktifan autentikasi dua faktor, pengelolaan kata sandi, dan pencadangan data. Materi presentasi dibagikan gratis kepada seluruh peserta." },
    ],
  },
  {
    slug: "kunjungan-industri-smk-teknik", title: "Kunjungan Industri SMK Teknik ke Lab Robotika PPTI - VMES", excerpt: "120 siswa SMK jurusan mekatronika mengenal robot industri, pneumatik, dan sistem kendali di lab PPTI.",
    category: "Kegiatan", dateLabel: "15 Okt 2026", publishedAt: 1792022400, author: "Tim Training PPTI", tags: ["Kunjungan", "Robotika"],
    body: [
      { type: "p", text: "Kunjungan industri ini menjadi bagian program link and match SMK dengan dunia industri. Siswa dibagi ke dalam kelompok kecil untuk mencoba langsung pemrograman robot lengan, rangkaian pneumatik, dan panel kendali motor." },
      { type: "p", text: "PPTI - VMES membuka kesempatan kunjungan serupa bagi sekolah dan kampus lain. Hubungi tim kami untuk penjadwalan." },
    ],
  },

  // ===== DOKUMENTASI (foto hotlink pihak ketiga — ganti dengan foto PPTI, lihat task.md §1.9) =====
  {
    slug: "dokumentasi-training-within-industry", title: "Dokumentasi Training Within Industry Batch Oktober 2026", excerpt: "Suasana kelas Training Within Industry (TWI) untuk supervisor produksi.",
    category: "Dokumentasi", dateLabel: "9 Nov 2026", publishedAt: 1794182400, image: "https://merca.team/wp-content/uploads/2026/02/training-within-industry-1024x683.webp", tags: ["TWI", "Supervisor"],
    body: [
      { type: "img", src: "https://merca.team/wp-content/uploads/2026/02/training-within-industry-1024x683.webp", alt: "Dokumentasi training PPTI", caption: "Sesi diskusi kelompok Training Within Industry." },
      { type: "p", text: "Dokumentasi kelas Training Within Industry (TWI) yang diikuti 14 supervisor dan leader produksi dari kawasan industri Batamindo dan Tunas." },
    ],
  },
  {
    slug: "dokumentasi-plc-scada-batch-oktober", title: "Dokumentasi Kelas PLC & SCADA Batch Oktober 2026", excerpt: "Praktik pemrograman PLC dan integrasi SCADA di lab otomasi PPTI - VMES.",
    category: "Dokumentasi", dateLabel: "2 Nov 2026", publishedAt: 1793577600, image: "https://livewireindia.com/blog/wp-content/uploads/2019/03/plc-scada-training-740x414.jpeg", tags: ["PLC", "SCADA"],
    body: [
      { type: "img", src: "https://livewireindia.com/blog/wp-content/uploads/2019/03/plc-scada-training-740x414.jpeg", alt: "Dokumentasi training PPTI", caption: "Peserta mengonfigurasi HMI dan SCADA." },
      { type: "p", text: "Peserta menyelesaikan proyek akhir berupa simulasi lini pengisian botol otomatis yang dikendalikan PLC dan dipantau lewat SCADA." },
    ],
  },
  {
    slug: "dokumentasi-agile-project-management", title: "Dokumentasi Workshop Agile Project Management", excerpt: "Workshop dua hari manajemen proyek agile untuk tim IT perusahaan manufaktur.",
    category: "Dokumentasi", dateLabel: "28 Okt 2026", publishedAt: 1793145600, image: "https://agile4training.com/assets/images/courses/course_2283_1757248573.webp", tags: ["Agile", "Manajemen Proyek"],
    body: [
      { type: "img", src: "https://agile4training.com/assets/images/courses/course_2283_1757248573.webp", alt: "Dokumentasi training PPTI" },
      { type: "p", text: "Workshop menggunakan simulasi sprint dengan papan kanban fisik, retrospektif, dan perencanaan rilis." },
    ],
  },
  {
    slug: "dokumentasi-hr-analytics", title: "Dokumentasi Training HR Analytics & KPI", excerpt: "Pelatihan pengukuran kinerja dan analitik SDM untuk tim HR.",
    category: "Dokumentasi", dateLabel: "21 Okt 2026", publishedAt: 1792540800, image: "https://www.arghajata.com/wp-content/uploads/2024/11/human-resources-management-software-computer-screen-helping-employee-to-manage-recruitment-payroll-staff-searching-kpi-319722581.webp", tags: ["HR", "KPI"],
    body: [
      { type: "img", src: "https://www.arghajata.com/wp-content/uploads/2024/11/human-resources-management-software-computer-screen-helping-employee-to-manage-recruitment-payroll-staff-searching-kpi-319722581.webp", alt: "Dokumentasi training PPTI" },
      { type: "p", text: "Peserta membangun dashboard KPI dan menganalisis data turnover karyawan menggunakan spreadsheet dan alat BI." },
    ],
  },
  {
    slug: "dokumentasi-lean-manufacturing", title: "Dokumentasi Training Lean Manufacturing & Kaizen", excerpt: "Pelatihan perbaikan berkelanjutan untuk tim produksi.",
    category: "Dokumentasi", dateLabel: "14 Okt 2026", publishedAt: 1791936000, image: "https://agile4training.com/assets/images/courses/course_2016_1743934487.webp", tags: ["Lean", "Kaizen"],
    body: [
      { type: "img", src: "https://agile4training.com/assets/images/courses/course_2016_1743934487.webp", alt: "Dokumentasi training PPTI" },
      { type: "p", text: "Materi 5S, value stream mapping, dan kaizen event dipraktikkan langsung dengan studi kasus lini produksi peserta." },
    ],
  },
  {
    slug: "dokumentasi-leadership", title: "Dokumentasi Training Leadership for Supervisor", excerpt: "Pengembangan kepemimpinan bagi supervisor dan team leader.",
    category: "Dokumentasi", dateLabel: "7 Okt 2026", publishedAt: 1791331200, image: "https://agile4training.com/assets/images/courses/course_1905_1744202959.webp", tags: ["Leadership"],
    body: [
      { type: "img", src: "https://agile4training.com/assets/images/courses/course_1905_1744202959.webp", alt: "Dokumentasi training PPTI" },
      { type: "p", text: "Sesi role-play coaching dan pemberian umpan balik menjadi bagian paling diminati peserta." },
    ],
  },
];

export const LATEST_ARTICLES = [...ARTICLES].filter((a) => a.category === "Tutorial").sort((a, b) => b.publishedAt - a.publishedAt).slice(0, 3);

export function getPostsByCategory(category: PostCategory): Article[] {
  return ARTICLES.filter((a) => a.category === category).sort((a, b) => b.publishedAt - a.publishedAt);
}

export function getPost(slug: string): Article | undefined {
  return ARTICLES.find((a) => a.slug === slug);
}

export function getCategoryBySlug(slug: string) {
  return POST_CATEGORIES.find((c) => c.slug === slug);
}

export function getCategorySlug(category: PostCategory): string {
  return POST_CATEGORIES.find((c) => c.category === category)?.slug ?? "berita";
}

/** Post terkait: kategori sama dulu, lalu sisanya; maksimal `n`. */
export function getRelatedPosts(post: Article, n = 3): Article[] {
  const others = ARTICLES.filter((a) => a.slug !== post.slug).sort((a, b) => b.publishedAt - a.publishedAt);
  return [...others.filter((a) => a.category === post.category), ...others.filter((a) => a.category !== post.category)].slice(0, n);
}
