import type { PostBlock } from "./articles";

// Halaman statis — website lama hanya punya placeholder ("Silakan ubah konten ini").
// Nanti GET /api/v1/pages/{slug} (CMS Laravel). Konten di bawah adalah draf untuk ditinjau tim PPTI.
export type StaticPage = {
  slug: string;
  title: string;
  description: string;
  updatedLabel?: string;
  body: PostBlock[];
};

export const STATIC_PAGES: StaticPage[] = [
  {
    slug: "kebijakan-privasi",
    title: "Kebijakan Privasi",
    description: "Kebijakan privasi PT. PPTI - VMES Training Center mengenai pengumpulan, penggunaan, dan perlindungan data pribadi peserta.",
    updatedLabel: "Diperbarui 10 November 2026",
    body: [
      { type: "p", text: "PT. PPTI - VMES Training Center (\"PPTI\", \"kami\") menghormati privasi Anda. Kebijakan ini menjelaskan data pribadi apa yang kami kumpulkan melalui situs pusatpelatihan.id dan layanan pelatihan kami, bagaimana data tersebut digunakan, serta hak-hak Anda." },
      { type: "h2", text: "1. Data yang Kami Kumpulkan" },
      { type: "ul", items: ["Data identitas: nama lengkap, tanggal lahir, dan (bila diperlukan untuk sertifikasi vendor) nomor identitas.", "Data kontak: alamat email, nomor telepon/WhatsApp, alamat instansi.", "Data pekerjaan: nama perusahaan/instansi, jabatan, bidang kerja.", "Data pembelajaran: kehadiran, nilai, tugas, dan progres di platform belajar.", "Data teknis: alamat IP, jenis peramban, dan halaman yang dikunjungi (melalui cookie analitik)."] },
      { type: "h2", text: "2. Penggunaan Data" },
      { type: "ul", items: ["Memproses pendaftaran, pembayaran, dan penerbitan sertifikat.", "Mengirim informasi jadwal, perubahan kelas, dan materi pelatihan.", "Memberikan akses ke portal peserta dan materi belajar daring.", "Mengirim penawaran program yang relevan (Anda dapat berhenti berlangganan kapan saja).", "Memenuhi kewajiban hukum dan audit."] },
      { type: "h2", text: "3. Pembagian Data" },
      { type: "p", text: "Kami tidak menjual data pribadi Anda. Data dapat dibagikan terbatas kepada mitra sertifikasi (misalnya Cisco, MikroTik, Pearson VUE) untuk pendaftaran ujian, penyedia layanan pembayaran, dan penyedia infrastruktur TI yang terikat perjanjian kerahasiaan." },
      { type: "h2", text: "4. Penyimpanan dan Keamanan" },
      { type: "p", text: "Data disimpan di server yang dilindungi enkripsi, kontrol akses berbasis peran, dan pencadangan terjadwal. Data pendaftaran dan sertifikat disimpan selama diperlukan untuk verifikasi sertifikat; data pemasaran disimpan hingga Anda meminta penghapusan." },
      { type: "h2", text: "5. Hak Anda" },
      { type: "p", text: "Anda berhak mengakses, memperbaiki, atau meminta penghapusan data pribadi Anda, serta menarik persetujuan pemasaran. Ajukan permintaan melalui WhatsApp resmi atau email yang tercantum di halaman Kontak." },
      { type: "h2", text: "6. Cookie" },
      { type: "p", text: "Situs ini menggunakan cookie fungsional dan analitik untuk meningkatkan pengalaman pengguna. Anda dapat menonaktifkan cookie melalui pengaturan peramban." },
      { type: "h2", text: "7. Perubahan Kebijakan" },
      { type: "p", text: "Kebijakan ini dapat diperbarui sewaktu-waktu. Versi terbaru selalu tersedia di halaman ini beserta tanggal pembaruannya." },
    ],
  },
  {
    slug: "syarat-ketentuan",
    title: "Syarat & Ketentuan",
    description: "Syarat dan ketentuan pendaftaran, pembayaran, pembatalan, dan pelaksanaan training di PPTI - VMES Training Center.",
    updatedLabel: "Diperbarui 10 November 2026",
    body: [
      { type: "p", text: "Dengan mendaftar program pelatihan PT. PPTI - VMES Training Center, peserta dan/atau instansi pengirim dianggap telah membaca dan menyetujui syarat dan ketentuan berikut." },
      { type: "h2", text: "1. Pendaftaran" },
      { type: "ul", items: ["Pendaftaran dilakukan melalui formulir daring, WhatsApp resmi, atau surat penawaran untuk pendaftaran kolektif.", "Pendaftaran dinyatakan sah setelah peserta menerima nomor registrasi dan konfirmasi dari admin PPTI.", "Kuota kelas terbatas dan diisi berdasarkan urutan konfirmasi pembayaran."] },
      { type: "h2", text: "2. Biaya dan Pembayaran" },
      { type: "ul", items: ["Biaya training tercantum di halaman katalog dan belum termasuk PPN kecuali dinyatakan lain.", "Pembayaran dilakukan melalui transfer bank ke rekening resmi PT. PPTI - VMES Training Center selambat-lambatnya 3 hari kerja sebelum kelas dimulai.", "Biaya sudah mencakup materi, sertifikat keikutsertaan, dan konsumsi untuk kelas offline. Voucher ujian sertifikasi vendor tercantum terpisah bila termasuk."] },
      { type: "h2", text: "3. Pembatalan dan Penjadwalan Ulang" },
      { type: "ul", items: ["Pembatalan oleh peserta lebih dari 7 hari sebelum kelas: pengembalian 100% atau pindah jadwal tanpa biaya.", "Pembatalan 3 sampai 7 hari sebelum kelas: pindah jadwal tanpa biaya, pengembalian dana dipotong biaya administrasi 25%.", "Pembatalan kurang dari 3 hari atau tidak hadir: biaya tidak dapat dikembalikan, dapat digantikan peserta lain dari instansi yang sama.", "PPTI berhak menunda atau membatalkan kelas bila kuota minimum tidak terpenuhi; peserta akan ditawari jadwal lain atau pengembalian dana penuh."] },
      { type: "h2", text: "4. Pelaksanaan Kelas" },
      { type: "ul", items: ["Peserta wajib hadir minimal 80% sesi untuk memperoleh sertifikat keikutsertaan.", "Peserta kelas online wajib mengaktifkan kamera selama sesi praktik dan asesmen.", "Materi pelatihan dilindungi hak cipta dan hanya untuk penggunaan pribadi peserta."] },
      { type: "h2", text: "5. Sertifikat" },
      { type: "p", text: "Sertifikat keikutsertaan diterbitkan oleh PPTI - VMES Training Center. Sertifikat kompetensi atau sertifikasi vendor diterbitkan oleh lembaga berwenang masing-masing setelah peserta lulus ujian yang dipersyaratkan." },
      { type: "h2", text: "6. Hubungi Kami" },
      { type: "p", text: "Pertanyaan mengenai syarat dan ketentuan dapat disampaikan melalui halaman Kontak." },
    ],
  },
  {
    slug: "faq",
    title: "FAQ",
    description: "Pertanyaan yang sering diajukan tentang training, pendaftaran, pembayaran, dan sertifikat di PPTI - VMES Training Center.",
    body: [
      { type: "h2", text: "Apakah training tersedia secara online?" },
      { type: "p", text: "Ya. Sebagian besar program tersedia dalam mode online (Zoom), offline di lab kami, atau hybrid. Mode setiap batch tercantum di halaman Jadwal." },
      { type: "h2", text: "Apakah saya mendapat sertifikat?" },
      { type: "p", text: "Setiap peserta yang memenuhi kehadiran minimal 80% memperoleh sertifikat keikutsertaan PPTI - VMES. Untuk sertifikasi vendor (Cisco, MikroTik, AWS, dll.) peserta mengikuti ujian resmi; beberapa paket sudah termasuk voucher ujian." },
      { type: "h2", text: "Bagaimana cara mendaftar?" },
      { type: "p", text: "Pilih batch di halaman Jadwal, klik Daftar, isi formulir, lalu lakukan pembayaran sesuai instruksi yang dikirim admin. Panduan lengkap ada di halaman Cara Pendaftaran." },
      { type: "h2", text: "Apakah ada diskon untuk pendaftaran kolektif?" },
      { type: "p", text: "Ada. Pendaftaran minimal 3 peserta dari instansi yang sama memperoleh diskon khusus. Untuk in-house training, hubungi tim kami untuk penawaran." },
      { type: "h2", text: "Apa yang perlu dibawa saat training?" },
      { type: "p", text: "Laptop pribadi (minimal RAM 8 GB) dan alat tulis. Perangkat lab seperti router, switch, PLC, dan trainer kit disediakan PPTI." },
      { type: "h2", text: "Apakah bisa menjadwalkan ulang jika berhalangan?" },
      { type: "p", text: "Bisa, tanpa biaya bila pemberitahuan dilakukan lebih dari 3 hari sebelum kelas dimulai. Lihat detail di Syarat & Ketentuan." },
      { type: "h2", text: "Di mana lokasi PPTI - VMES Training Center?" },
      { type: "p", text: "Kantor pusat dan lab utama berada di Batam Centre, Kawasan Industri Tunas Bizpark Blok D No 15 Lantai 2, Kota Batam. Kelas offline juga diselenggarakan di Jakarta, Bandung, dan Surabaya sesuai jadwal." },
    ],
  },
  {
    slug: "cara-pendaftaran",
    title: "Cara Pendaftaran",
    description: "Langkah-langkah mendaftar training di PPTI - VMES Training Center, dari memilih jadwal hingga menerima sertifikat.",
    body: [
      { type: "p", text: "Pendaftaran training di PPTI - VMES Training Center hanya memerlukan beberapa langkah. Tim admin kami akan mendampingi Anda hingga kelas dimulai." },
      { type: "h2", text: "Langkah Pendaftaran" },
      { type: "ol", items: ["Pilih program di halaman Kursus dan cek batch yang tersedia di halaman Jadwal.", "Klik tombol Daftar pada batch yang diinginkan, lalu isi formulir pendaftaran (data diri, instansi, kontak).", "Anda menerima nomor registrasi dan rincian biaya melalui email/WhatsApp dalam 1 hari kerja.", "Lakukan pembayaran ke rekening resmi PT. PPTI - VMES Training Center dan unggah/kirim bukti transfer.", "Setelah pembayaran diverifikasi, Anda menerima konfirmasi kursi, undangan kelas, dan akses portal peserta.", "Ikuti kelas, selesaikan asesmen, dan unduh sertifikat dari portal peserta."] },
      { type: "h2", text: "Pendaftaran Kolektif / Perusahaan" },
      { type: "p", text: "Untuk pendaftaran lebih dari satu peserta atau in-house training, kirimkan daftar peserta dan kebutuhan materi melalui WhatsApp resmi atau formulir konsultasi di halaman Kontak. Kami akan mengirim penawaran resmi beserta jadwal yang disesuaikan." },
      { type: "h2", text: "Dokumen yang Diperlukan" },
      { type: "ul", items: ["Nama lengkap sesuai identitas (untuk pencetakan sertifikat)", "Alamat email dan nomor WhatsApp aktif", "Surat tugas dari instansi (untuk peserta korporasi, opsional)"] },
      { type: "h2", text: "Butuh Bantuan?" },
      { type: "p", text: "Hubungi admin kami di WhatsApp 0851-2125-1285 pada hari kerja pukul 08.00 sampai 17.00 WIB." },
    ],
  },
];

export function getStaticPage(slug: string): StaticPage | undefined {
  return STATIC_PAGES.find((p) => p.slug === slug);
}
