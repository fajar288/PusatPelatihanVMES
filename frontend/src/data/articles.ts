// Artikel terbaru — website lama hanya punya 1 post bawaan WordPress ("Hello world!").
// Diisi 3 artikel contoh bergaya tutorial (judul diambil dari konten contoh kategori Tutorial).
export type Article = {
  slug: string;
  title: string;
  excerpt: string;
  category: string;
  dateLabel: string;
  image?: string;
};

export const ARTICLES: Article[] = [
  { slug: "konfigurasi-eigrp", title: "Konfigurasi Enhanced Interior Gateway Routing Protocol (EIGRP)", excerpt: "Contoh konten. Tulis postingan berlabel Tutorial untuk menggantikan tampilan contoh ini.", category: "Tutorial", dateLabel: "10 Nov 2026" },
  { slug: "konfigurasi-vlan-trunking", title: "Konfigurasi VLAN dan Trunking pada Switch", excerpt: "Contoh konten. Tulis postingan berlabel Tutorial untuk menggantikan tampilan contoh ini.", category: "Tutorial", dateLabel: "7 Nov 2026" },
  { slug: "dasar-docker-pemula", title: "Dasar Docker untuk Pemula", excerpt: "Contoh konten. Tulis postingan berlabel Tutorial untuk menggantikan tampilan contoh ini.", category: "Tutorial", dateLabel: "4 Nov 2026" },
];
