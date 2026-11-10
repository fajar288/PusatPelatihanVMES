// Bentuk data API portal member (/api/v1/me/*, /auth/*, /registrations) — lihat backend App\Http\Resources\{User,Registration,Payment,AuditLog}Resource.

export type MemberProfile = {
  memberNumber: string;
  status: "registered" | "active" | "alumni" | "inactive";
  phone: string | null;
  birthDate: string | null;
  gender: "male" | "female" | null;
  company: string | null;
  position: string | null;
  education: string | null;
  address: string | null;
  city: string | null;
  photo: string | null;
  communicationPref: { email: boolean; whatsapp: boolean };
  since: string | null;
};

export type PortalUser = {
  id: number;
  name: string;
  email: string;
  role: string;
  roles: string[];
  isAdmin: boolean;
  member: MemberProfile | null;
};

export type RegistrationStatus = "pending" | "under_review" | "waiting_payment" | "payment_verification" | "confirmed" | "rejected" | "cancelled";

export type PaymentInfo = {
  invoice: string;
  registration?: string;
  amount: number;
  dueDate: string | null;
  dueLabel: string | null;
  method: string;
  status: "unpaid" | "submitted" | "verified" | "rejected" | "refunded";
  proofUploaded: boolean;
  proofUploadedAt: string | null;
  verifiedAt: string | null;
  note: string | null;
  createdAt: string | null;
};

export type RegistrationInfo = {
  number: string;
  type: "individual" | "corporate";
  status: RegistrationStatus;
  statusLabel: string;
  nextStatuses: RegistrationStatus[];
  cancellable: boolean;
  name: string;
  email: string;
  phone: string;
  company: string | null;
  position: string | null;
  participantsCount: number;
  notes: string | null;
  priceQuoted: number | null;
  discount: number;
  total: number | null;
  statusNote: string | null;
  createdAt: string | null;
  createdLabel: string | null;
  confirmedAt: string | null;
  schedule: {
    code: string;
    title: string;
    packageSlug: string | null;
    startDate: string;
    endDate: string;
    dateLabel: string;
    city: string | null;
    mode: string;
    time: string | null;
  } | null;
  participants?: { name: string; email: string | null; phone: string | null; position: string | null }[];
  payments?: PaymentInfo[];
};

export type AuditEvent = {
  id: number;
  action: string;
  actor: string | null;
  subject: string | null;
  subjectId: number | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  at: string;
};

export type EnrollmentInfo = {
  id: number;
  status: "enrolled" | "attending" | "completed" | "failed" | "dropped";
  participantName: string;
  registration: string | null;
  enrolledAt: string | null;
  completedAt: string | null;
  finalScore: number | null;
  attendancePercent: number | null;
  schedule: {
    code: string;
    title: string;
    packageSlug: string | null;
    startDate: string;
    endDate: string;
    dateLabel: string;
    city: string | null;
    location: string | null;
    mode: string;
    time: string | null;
    classStatus: string;
    trainers: { name: string; role: string }[];
  } | null;
  sessions: { no: number; date: string; dateLabel: string; topic: string | null; time: string | null; attendance: string | null; attendanceLabel: string | null }[];
};

/** Label & warna pill status (dipetakan ke .jst ok|wn|no tema). */
export const STATUS_PILL: Record<RegistrationStatus, { label: string; cls: "ok" | "wn" | "no" }> = {
  pending: { label: "Menunggu diproses", cls: "wn" },
  under_review: { label: "Sedang ditinjau", cls: "wn" },
  waiting_payment: { label: "Menunggu pembayaran", cls: "wn" },
  payment_verification: { label: "Verifikasi pembayaran", cls: "wn" },
  confirmed: { label: "Terkonfirmasi", cls: "ok" },
  rejected: { label: "Ditolak", cls: "no" },
  cancelled: { label: "Dibatalkan", cls: "no" },
};

export const PAYMENT_PILL: Record<PaymentInfo["status"], { label: string; cls: "ok" | "wn" | "no" }> = {
  unpaid: { label: "Belum dibayar", cls: "wn" },
  submitted: { label: "Menunggu verifikasi", cls: "wn" },
  verified: { label: "Terverifikasi", cls: "ok" },
  rejected: { label: "Ditolak", cls: "no" },
  refunded: { label: "Dikembalikan", cls: "no" },
};

/** Teks notifikasi aplikasi dari aksi audit. */
export function describeEvent(e: AuditEvent): string {
  const after = (e.after ?? {}) as Record<string, string>;
  switch (e.action) {
    case "registration.created":
      return `Pendaftaran ${after.number ?? ""} diterima dan menunggu diproses.`;
    case "registration.status_changed":
      return `Status pendaftaran berubah menjadi "${STATUS_PILL[after.status as RegistrationStatus]?.label ?? after.status}"${after.note ? `: ${after.note}` : "."}`;
    case "payment.proof_uploaded":
      return "Bukti pembayaran diunggah dan menunggu verifikasi.";
    case "member.created":
      return `Akun member ${after.member_number ?? ""} dibuat.`;
    case "member.profile_updated":
      return "Profil diperbarui.";
    default:
      return e.action;
  }
}
