// Testimoni — salinan persis dari JSON #ppti-testi website lama (docs/reference/testimonials.json).
export type Testimonial = {
  name: string;
  role: string;
  quote: string;
  image?: string; // kosong → avatar default
  program?: string;
  stars: number;
};

export const TESTIMONIALS: Testimonial[] = [
  {
    name: "Rizky Pratama",
    role: "Automation Technician • PT. Batam Industrial Automation • Batam",
    quote: "Saya sebelumnya hanya memahami dasar-dasar kelistrikan industri. Setelah mengikuti pelatihan PLC di PPTI VMES Training Center, saya mulai memahami pemrograman PLC, logika kontrol, dan cara melakukan pengujian sistem secara langsung. Praktik yang diberikan membantu saya meningkatkan keterampilan teknis dan lebih percaya diri dalam menangani sistem otomasi di lingkungan kerja.",
    program: "Pelatihan PLC",
    stars: 5,
  },
  {
    name: "Andika Saputra",
    role: "Automation Engineer • PT. Prima Teknik Industri • Batam",
    quote: "Saya mengikuti pelatihan otomasi industri untuk memperdalam pemahaman tentang PLC, HMI, dan sistem kontrol mesin. Selama pelatihan, saya mendapatkan pengalaman praktik yang membantu saya memahami hubungan antara perangkat kontrol dan proses produksi. Materinya aplikatif dan relevan dengan kebutuhan industri manufaktur.",
    program: "Pelatihan Otomasi Industri",
    stars: 5,
  },
  {
    name: "Nadia Putri Amelia",
    role: "Data Analyst • PT. Digital Solusi Nusantara • Jakarta",
    quote: "Dalam pekerjaan sehari-hari, saya membutuhkan kemampuan mengolah data dengan lebih efektif. Melalui pelatihan IT di PPTI VMES Training Center, saya belajar menggunakan perangkat analisis data untuk menyusun laporan dan menyajikan informasi secara lebih terstruktur. Pelatihan ini membantu saya memahami bagaimana teknologi dapat mendukung pekerjaan dan pengambilan keputusan.",
    program: "Pelatihan IT dan Data Analytics",
    stars: 5,
  },
  {
    name: "Dewi Anggraini",
    role: "Human Resources Officer • PT. Mitra Karya Digital • Batam",
    quote: "Saya ingin memahami bagaimana AI dapat dimanfaatkan untuk mendukung pekerjaan di bidang sumber daya manusia. Melalui pelatihan SDM Digital di PPTI VMES Training Center, saya mempelajari penggunaan teknologi AI untuk membantu penyusunan dokumen, pengolahan informasi, dan peningkatan produktivitas. Pengetahuan ini menjadi bekal untuk beradaptasi dengan perkembangan teknologi di lingkungan kerja.",
    program: "Pelatihan AI dan SDM Digital",
    stars: 5,
  },
  {
    name: "Muhammad Farhan",
    role: "IT Support Specialist • PT. Inovasi Teknologi Mandiri • Tanjung Pinang",
    quote: "Saya mengikuti pelatihan pemrograman dan database untuk memperkuat kemampuan teknis di bidang teknologi informasi. Materi yang disampaikan secara bertahap membantu saya memahami dasar pengembangan aplikasi dan pengelolaan database. Dengan latihan yang terarah, saya memperoleh pemahaman yang lebih baik tentang penerapan IT untuk mendukung kebutuhan operasional perusahaan.",
    program: "Pelatihan Pemrograman dan Database",
    stars: 5,
  },
];
