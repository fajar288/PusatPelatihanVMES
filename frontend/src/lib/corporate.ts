// Corporate Portal (Roadmap 5 tahap 5B): tipe & helper. Semua data diambil di server dengan token sesi (cookie httpOnly);
// organisasi di URL selalu diverifikasi API terhadap keanggotaan aktif pengguna (organisasi lain → 404).
import type { ParticipantOverview } from "./monitoring";
import type { InvoiceInfo } from "./portal-types";

export type Capability = "view_progress" | "manage_participants" | "manage_requests" | "approve_requests" | "manage_users" | "view_finance" | "export" | "manage_competency";

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

/** GET .../invoices: invoice organisasi (peran view_finance) + ringkasan piutang. */
export type CorporateInvoiceList = {
  data: InvoiceInfo[];
  summary: { outstanding: number; openCount: number; paid: number; awaiting: number };
};

// ---- Analitik perusahaan & kompetensi (Roadmap 5F, §20.15-§20.16; definisi KPI: docs/KPI.md) ----

export type KpiKey = "completion_rate" | "attendance_rate" | "repeat_order_rate" | "average_order_value" | "training_conversion" | "at_risk_rate";

export type Kpi = { value: number | null; numerator: number; denominator: number; label: string; definition: string; display: string; not_applicable?: boolean; pending_evaluation?: number };

export type CorporateAnalytics = {
  period: { from: string; to: string };
  kpi: Record<KpiKey, Kpi>;
  utilization: { requested: number; bought: number; assigned: number; enrolled: number; used: number; rate: number | null };
  participants: number;
  enrollments: number;
  byBatch: { code: string; program: string | null; dates: string; participants: number; attendanceRate: number | null; completed: number; completionRate: number | null; hours: number }[];
  byUnit: { unitId: number | null; unit: string; participants: number; enrollments: number; attendanceRate: number | null; completed: number; hours: number; hoursPerEmployee: number | null }[];
  trainingHours: { total: number; perEmployee: number | null };
  programs: { program: string | null; slug: string | null; participants: number; completed: number; completionRate: number | null; avgScore: number | null; competentRate: number | null; avgCompetency: number | null }[];
  repeat: { orders: number; isRepeat: boolean; programs: number; firstOrder: string | null; lastOrder: string | null; daysToRepeat: number | null };
  cost: { total: number; orders: number; perEmployee: number | null; byUnit: { unit: string; orders: number; amount: number }[] } | null;
  risk: { count: number; active: number; rate: number | null; rows: { enrollmentId: number; name: string; class: string; program: string | null; attendance: number | null; lms: number | null; reasons: string[] }[] | null };
  competency: CompetencySummary[];
};

export type CompetencySummary = { competency_id: number; competency: string; code: string; employees: number; met: number; coverage: number | null; no_evidence: number; avg_gap: number };

export type CompetencyTargetRow = { id: number; position: string; competency: string | null; competencyName: string | null; requiredLevel: number };

export type CompetencyGapRow = {
  employmentId: number;
  name: string | null;
  employeeId: string | null;
  position: string | null;
  unit: string | null;
  competency: string;
  competencyName: string;
  required: number;
  actual: number | null;
  source: "assessment" | "training" | null;
  gap: number;
  met: boolean;
  recommendation: { slug: string; title: string; level: number } | null;
};

export type CorporateCompetencies = {
  canManage: boolean;
  catalog: { code: string; name: string; category: string | null; description: string | null }[];
  targets: CompetencyTargetRow[];
  positions: string[];
  employees: number;
  employeesWithTargets: number;
  summary: CompetencySummary[];
  rows: CompetencyGapRow[] | null;
};

/** GET /corporate/organizations/{org}/insight - insight kompetensi (dibuat AI, sudah disetujui admin VMES). */
export type CorporateInsight = {
  insightId: number;
  summary: string;
  keyGaps: { competency: string; finding: string }[];
  actions: { action: string; reason: string; priority: "tinggi" | "sedang" | "rendah"; program: { slug: string; title: string } | null }[];
  limitations: string;
  approvedAt: string | null;
  myFeedback: boolean | null;
};

export type CorporateInsightResponse = { status: { available: boolean; label: string; reason: string | null }; canView: boolean; insight: CorporateInsight | null };

export function pct(v: number | null | undefined): string {
  return v === null || v === undefined ? "-" : `${String(v).replace(".", ",")}%`;
}
