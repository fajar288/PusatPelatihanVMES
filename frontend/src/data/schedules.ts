// Jadwal batch dummy — 8 baris pertama identik dengan "Jadwal Terdekat" website lama.
// Nanti GET /api/v1/schedules dan /api/v1/schedules/{id} (Laravel).
export type ScheduleStatus = "ok" | "wn" | "no";

export type Schedule = {
  id: string;
  code: string; // kode kelas (PRD §5.4)
  courseSlug: string;
  title: string;
  category: string;
  city: string;
  location: string;
  mode: string;
  dateLabel: string; // tampilan "17 November 2026"
  startDate: string; // ISO "2026-11-17"
  endDate: string;
  time: string; // "08.30 - 16.30 WIB"
  durationDays: number;
  quota: number;
  remaining: number;
  trainer: string;
  status: ScheduleStatus;
  statusLabel: string;
  price: number;
  publishedAt: number; // unix, urutan "Terbaru" di /jadwal
  certificate?: string | null; // dari paket (API); data statis mengambilnya lewat getCourse()
};

export const SCHEDULES: Schedule[] = [
  { id: "sch-1", code: "CCNA-2611", courseSlug: "cisco-ccna", title: "Cisco CCNA", category: "Networking", city: "Batam", location: "Online via Zoom (opsi offline di lab PPTI Batam)", mode: "online", dateLabel: "17 November 2026", startDate: "2026-11-17", endDate: "2026-11-21", time: "08.30 - 16.30 WIB", durationDays: 5, quota: 16, remaining: 9, trainer: "Andi Pratama, CCNP", status: "ok", statusLabel: "Tersedia", price: 7500000, publishedAt: 1794268800 },
  { id: "sch-2", code: "MTCNA-2611", courseSlug: "mikrotik-mtcna", title: "MikroTik MTCNA", category: "Networking", city: "Jakarta", location: "Lab Jakarta - Gedung Graha Mustika, Jakarta Selatan", mode: "offline", dateLabel: "24 November 2026", startDate: "2026-11-24", endDate: "2026-11-26", time: "09.00 - 16.00 WIB", durationDays: 3, quota: 12, remaining: 3, trainer: "Budi Santoso, MTCINE", status: "wn", statusLabel: "Terbatas", price: 5500000, publishedAt: 1794873600 },
  { id: "sch-3", code: "AWSCP-2612", courseSlug: "aws-cloud-practitioner", title: "AWS Cloud Practitioner", category: "Cloud Computing", city: "Bandung", location: "Hybrid - Lab Bandung & Zoom", mode: "hybrid", dateLabel: "1 Desember 2026", startDate: "2026-12-01", endDate: "2026-12-03", time: "08.30 - 16.30 WIB", durationDays: 3, quota: 16, remaining: 14, trainer: "Rina Kusuma, AWS SA Associate", status: "ok", statusLabel: "Tersedia (Sisa 14)", price: 7000000, publishedAt: 1795478400 },
  { id: "sch-4", code: "DEVOPS-2612", courseSlug: "devops-engineering", title: "DevOps Engineering", category: "DevOps", city: "Online", location: "Online via Zoom", mode: "online", dateLabel: "8 Desember 2026", startDate: "2026-12-08", endDate: "2026-12-12", time: "08.30 - 16.30 WIB", durationDays: 5, quota: 20, remaining: 12, trainer: "Dimas Wijaya, CKA", status: "ok", statusLabel: "Tersedia", price: 8500000, publishedAt: 1796083200 },
  { id: "sch-5", code: "CYBER-2612", courseSlug: "cyber-security-foundation", title: "Cyber Security Foundation", category: "Cyber Security", city: "Online", location: "Online via Zoom", mode: "online", dateLabel: "15 Desember 2026", startDate: "2026-12-15", endDate: "2026-12-18", time: "08.30 - 16.30 WIB", durationDays: 4, quota: 20, remaining: 20, trainer: "Fajar Nugroho, CEH", status: "ok", statusLabel: "Tersedia", price: 6500000, publishedAt: 1796688000 },
  { id: "sch-6", code: "PY-2612", courseSlug: "python-programming", title: "Python Programming", category: "Programming", city: "Batam", location: "Lab PPTI - VMES, Tunas Bizpark Batam Centre", mode: "offline", dateLabel: "22 Desember 2026", startDate: "2026-12-22", endDate: "2026-12-25", time: "08.30 - 16.30 WIB", durationDays: 4, quota: 12, remaining: 10, trainer: "Sari Dewi, M.Kom", status: "ok", statusLabel: "Tersedia", price: 4500000, publishedAt: 1797292800 },
  { id: "sch-7", code: "CCNP-2612", courseSlug: "cisco-ccnp-enterprise", title: "Cisco CCNP Enterprise", category: "Networking", city: "Jakarta", location: "Lab Jakarta - Gedung Graha Mustika, Jakarta Selatan", mode: "offline", dateLabel: "29 Desember 2026", startDate: "2026-12-29", endDate: "2027-01-02", time: "09.00 - 16.00 WIB", durationDays: 5, quota: 10, remaining: 2, trainer: "Andi Pratama, CCNP", status: "wn", statusLabel: "Terbatas", price: 9500000, publishedAt: 1797897600 },
  { id: "sch-8", code: "LNX-2701", courseSlug: "linux-administration", title: "Linux Administration", category: "DevOps", city: "Surabaya", location: "Hybrid - Lab Surabaya & Zoom", mode: "hybrid", dateLabel: "5 Januari 2027", startDate: "2027-01-05", endDate: "2027-01-08", time: "08.30 - 16.30 WIB", durationDays: 4, quota: 12, remaining: 0, trainer: "Dimas Wijaya, RHCSA", status: "no", statusLabel: "Penuh", price: 5000000, publishedAt: 1798502400 },
  { id: "sch-9", code: "CCNA-2701", courseSlug: "cisco-ccna", title: "Cisco CCNA", category: "Networking", city: "Batam", location: "Lab PPTI - VMES, Tunas Bizpark Batam Centre", mode: "offline", dateLabel: "12 Januari 2027", startDate: "2027-01-12", endDate: "2027-01-16", time: "08.30 - 16.30 WIB", durationDays: 5, quota: 16, remaining: 16, trainer: "Andi Pratama, CCNP", status: "ok", statusLabel: "Tersedia", price: 7500000, publishedAt: 1799107200 },
  { id: "sch-10", code: "AWSCP-2701", courseSlug: "aws-cloud-practitioner", title: "AWS Cloud Practitioner", category: "Cloud Computing", city: "Online", location: "Online via Zoom", mode: "online", dateLabel: "19 Januari 2027", startDate: "2027-01-19", endDate: "2027-01-21", time: "08.30 - 16.30 WIB", durationDays: 3, quota: 20, remaining: 18, trainer: "Rina Kusuma, AWS SA Associate", status: "ok", statusLabel: "Tersedia", price: 7000000, publishedAt: 1799712000 },
];

const MONTHS_ID = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];

/** "Cisco CCNA - Batch November" (format judul postingan jadwal di website lama). */
export function scheduleBatchTitle(s: Schedule): string {
  const m = Number(s.startDate.slice(5, 7)) - 1;
  return `${s.title} - Batch ${MONTHS_ID[m]}`;
}

/** "17 Nov 2026" (format <time> di list jadwal website lama). */
export function shortDate(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  return `${d} ${MONTHS_ID[m - 1].slice(0, 3)} ${y}`;
}

export function getSchedule(id: string): Schedule | undefined {
  return SCHEDULES.find((s) => s.id === id);
}

export function getUpcomingSchedules(): Schedule[] {
  return [...SCHEDULES].sort((a, b) => a.startDate.localeCompare(b.startDate));
}

export { MONTHS_ID };
