// Corporate Portal (Roadmap 5 tahap 5B) — tipe & helper. Semua data diambil di server dengan token sesi (cookie httpOnly);
// organisasi di URL selalu diverifikasi API terhadap keanggotaan aktif pengguna (organisasi lain → 404).
import type { ParticipantOverview } from "./monitoring";
import type { InvoiceInfo } from "./portal-types";

export type Capability = "view_progress" | "manage_participants" | "manage_requests" | "approve_requests" | "manage_users" | "view_finance" | "export";

export type CorporateOrg = { code: string; name: string; role: string; roleLabel: string; unit: string | null };

export type CorporateSummary = {
  organization: { code: string; name: string; legalName: string; logo: string | null; requiresApproval: boolean };
  me: { role: string; roleLabel: string; unit: string | null; capabilities: Capability[] };
  stats: {
    participants: number;
    activeEnrollments: number;
    completed: number;
    completionRate: number | null;
    attendanceAvg: number | null;
    certificates: number;
    activeEmployees: number | null;
    requests: Record<string, number>;
    seatsBought: number;
    seatsFilled: number;
  };
};

export type RequestStatus = "draft" | "submitted" | "quoted" | "approval" | "approved" | "paid" | "scheduled" | "in_progress" | "completed" | "cancelled";

export type SeatRow = {
  id: number;
  no: number;
  status: "reserved" | "invited" | "assigned" | "enrolled";
  statusLabel: string;
  name: string | null;
  email: string | null;
  unit: string | null;
  method: string | null;
  enrollmentId: number | null;
  inviteExpires: string | null;
};

export type CorporateRequest = {
  code: string;
  status: RequestStatus;
  statusLabel: string;
  program: string | null;
  package: string | null;
  delivery: string;
  deliveryLabel: string;
  targetDate: string | null;
  targetLabel: string | null;
  location: string | null;
  unit: string | null;
  unitId: number | null;
  seats: number;
  filled: number;
  invited: number;
  schedule: { code: string; dates: string } | null;
  createdAt: string | null;
  quote: { pricePerSeat: number; discount: number; total: number; validUntil: string | null; notes: string | null; expired: boolean } | null;
  invoice?: { number: string; status: string; statusLabel: string; total: number; outstanding: number; dueLabel: string | null; canOpen: boolean } | null;
  can: { edit: boolean; submit: boolean; acceptQuote: boolean; approve: boolean; cancel: boolean; assign: boolean };
  trainerRequirements?: string | null;
  notes?: string | null;
  cancelReason?: string | null;
  timeline?: Record<string, string>;
  seatList?: SeatRow[];
};

export type CorporateEnrollment = {
  id: number;
  name: string;
  memberNumber: string | null;
  employeeId: string | null;
  employment: "active" | "inactive" | "unknown";
  program: string | null;
  schedule: string | null;
  dates: string | null;
  unit: string | null;
  status: string;
  attendancePercent: number | null;
  progressVisible: boolean;
  certificateStatus: string | null;
};

export type CorporateProgress = Omit<ParticipantOverview, "enrollment"> & {
  enrollment: { id: number; name: string; status: string; finalScore: number | null };
  participant: { name: string; memberNumber: string | null; employeeId: string | null; position: string | null; unit: string | null; employment: string | null };
  certificate: { status: string; number: string | null; issuedAt: string | null } | null;
};

export type Employee = { id: number; name: string | null; employeeId: string | null; position: string | null; unit: string | null; since: string | null };
export type Unit = { id: number; name: string; code: string | null; type: string; label: string };
export type CorporateUserRow = { id: number; name: string | null; email: string | null; role: string; roleLabel: string; unitId: number | null; unit: string | null; status: string; isMe: boolean };
export type CertificateRow = { enrollmentId: number; name: string; program: string | null; schedule: string | null; status: string | null; number: string | null; issuedAt: string | null };
export type ImportRow = { row: number; name: string | null; email: string | null; status: "assigned" | "invited" | "review" | "error"; message: string; seat: number | null };

export const ROLE_OPTIONS: { value: string; label: string; hint: string }[] = [
  { value: "owner", label: "Corporate Owner", hint: "Akses penuh workspace perusahaan, menyetujui permintaan, mengelola pengguna" },
  { value: "hr_admin", label: "HR/L&D Admin", hint: "Permintaan training, penugasan peserta, progres, laporan" },
  { value: "supervisor", label: "Supervisor", hint: "Melihat progres unit/departemennya" },
  { value: "finance", label: "Finance", hint: "Penawaran, tagihan & pembayaran" },
  { value: "viewer", label: "Viewer", hint: "Dashboard baca saja" },
];

export const REQUEST_PILL: Record<RequestStatus, "ok" | "wn" | "no"> = {
  draft: "no",
  submitted: "wn",
  quoted: "wn",
  approval: "wn",
  approved: "ok",
  paid: "ok",
  scheduled: "ok",
  in_progress: "ok",
  completed: "ok",
  cancelled: "no",
};

export const CERT_STATUS: Record<string, string> = { draft: "Draf", pending_validation: "Menunggu validasi", issued: "Terbit", revoked: "Dicabut", replaced: "Diganti" };

export function orgPath(org: string, sub = ""): string {
  return `/corporate/organizations/${encodeURIComponent(org)}${sub}`;
}


export function can(s: CorporateSummary, c: Capability): boolean {
  return s.me.capabilities.includes(c);
}

export function rupiah(n: number | null | undefined): string {
  return n === null || n === undefined ? "-" : "Rp " + Math.round(n).toLocaleString("id-ID");
}

/** GET .../invoices — invoice organisasi (peran view_finance) + ringkasan piutang. */
export type CorporateInvoiceList = {
  data: InvoiceInfo[];
  summary: { outstanding: number; openCount: number; paid: number; awaiting: number };
};
