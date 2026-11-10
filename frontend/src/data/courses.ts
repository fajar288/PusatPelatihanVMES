// Data kursus dummy — identik dengan konten contoh tema pusatpelatihan.id.
// Nanti diganti dengan GET /api/v1/packages (Laravel).
export type CourseStatus = "ok" | "no";

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
};

export const COURSES: Course[] = [
  { slug: "cisco-ccna", title: "Cisco CCNA", category: "Networking", vendor: "Cisco", desc: "Training fundamental networking dan persiapan sertifikasi CCNA.", durationDays: 5, mode: "online / offline", status: "ok", statusLabel: "Tersedia", price: 7500000, popular: true },
  { slug: "aws-cloud-practitioner", title: "AWS Cloud Practitioner", category: "Cloud Computing", vendor: "AWS", desc: "Pelatihan cloud fundamental menggunakan Amazon Web Services.", durationDays: 3, mode: "hybrid", status: "ok", statusLabel: "Tersedia", price: 7000000, popular: true },
  { slug: "devops-engineering", title: "DevOps Engineering", category: "DevOps", vendor: "DevOps", desc: "Praktik CI/CD, container, dan otomasi infrastruktur.", durationDays: 5, mode: "online", status: "ok", statusLabel: "Tersedia", price: 8500000 },
  { slug: "mikrotik-mtcna", title: "MikroTik MTCNA", category: "Networking", vendor: "MikroTik", desc: "Konfigurasi router dan jaringan dengan MikroTik RouterOS.", durationDays: 3, mode: "offline", status: "ok", statusLabel: "Tersedia", price: 5500000 },
  { slug: "cyber-security-foundation", title: "Cyber Security Foundation", category: "Cyber Security", vendor: "Cyber Security", desc: "Dasar keamanan informasi dan pertahanan jaringan.", durationDays: 4, mode: "online", status: "no", statusLabel: "Segera dibuka", price: 6500000 },
  { slug: "python-programming", title: "Python Programming", category: "Programming", vendor: "Programming", desc: "Dasar pemrograman Python untuk otomasi dan analisis data.", durationDays: 4, mode: "hybrid", status: "ok", statusLabel: "Tersedia", price: 4500000 },
];
