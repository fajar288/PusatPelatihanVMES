// Jadwal terdekat dummy — identik dengan website lama. Nanti GET /api/v1/schedules.
export type ScheduleStatus = "ok" | "wn" | "no";

export type Schedule = {
  id: string;
  courseSlug: string;
  title: string;
  city: string;
  mode: string;
  dateLabel: string; // tampilan "17 November 2026"
  durationDays: number;
  status: ScheduleStatus;
  statusLabel: string;
  price: number;
};

export const SCHEDULES: Schedule[] = [
  { id: "sch-1", courseSlug: "cisco-ccna", title: "Cisco CCNA", city: "Batam", mode: "online", dateLabel: "17 November 2026", durationDays: 5, status: "ok", statusLabel: "Tersedia", price: 7500000 },
  { id: "sch-2", courseSlug: "mikrotik-mtcna", title: "MikroTik MTCNA", city: "Jakarta", mode: "offline", dateLabel: "24 November 2026", durationDays: 3, status: "wn", statusLabel: "Terbatas", price: 5500000 },
  { id: "sch-3", courseSlug: "aws-cloud-practitioner", title: "AWS Cloud Practitioner", city: "Bandung", mode: "hybrid", dateLabel: "1 Desember 2026", durationDays: 3, status: "ok", statusLabel: "Tersedia (Sisa 14)", price: 7000000 },
  { id: "sch-4", courseSlug: "devops-engineering", title: "DevOps Engineering", city: "Online", mode: "online", dateLabel: "8 Desember 2026", durationDays: 5, status: "ok", statusLabel: "Tersedia", price: 8500000 },
  { id: "sch-5", courseSlug: "cyber-security-foundation", title: "Cyber Security Foundation", city: "Online", mode: "online", dateLabel: "15 Desember 2026", durationDays: 4, status: "ok", statusLabel: "Tersedia", price: 6500000 },
  { id: "sch-6", courseSlug: "python-programming", title: "Python Programming", city: "Batam", mode: "offline", dateLabel: "22 Desember 2026", durationDays: 4, status: "ok", statusLabel: "Tersedia", price: 4500000 },
  { id: "sch-7", courseSlug: "cisco-ccnp-enterprise", title: "Cisco CCNP Enterprise", city: "Jakarta", mode: "offline", dateLabel: "29 Desember 2026", durationDays: 5, status: "wn", statusLabel: "Terbatas", price: 9500000 },
  { id: "sch-8", courseSlug: "linux-administration", title: "Linux Administration", city: "Surabaya", mode: "hybrid", dateLabel: "5 Januari 2027", durationDays: 4, status: "no", statusLabel: "Penuh", price: 5000000 },
];
