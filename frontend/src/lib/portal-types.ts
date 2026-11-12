// Bentuk data API portal member (/api/v1/me/*, /auth/*, /registrations) — lihat backend App\Http\Resources\{User,Registration,Invoice,AuditLog}Resource.
import type { EnrollmentCertificate } from "./certificates";

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
  unreadNotifications?: number;
  emailVerified?: boolean;
  /** Organisasi tempat akun ini menjadi pengguna perusahaan aktif (Corporate Portal, Roadmap 5). */
  corporate?: { code: string; name: string; role: string }[];
  member: MemberProfile | null;
};

export type RegistrationStatus = "pending" | "under_review" | "waiting_payment" | "payment_verification" | "confirmed" | "rejected" | "cancelled";

/** Invoice (Roadmap 5C) — backend App\Http\Resources\InvoiceResource. Pembayaran manual: status berubah hanya lewat verifikasi finance. */
export type InvoiceStatus = "pending" | "paid" | "failed" | "expired" | "refunded" | "cancelled";

export type InvoiceTransaction = {
  code: string;
  method: string;
  destination: string | null;
  amount: number;
  amountReceived: number | null;
  transferLabel: string | null;
  status: "pending" | "paid" | "failed" | "cancelled";
  statusLabel: string;
  submittedAt: string | null;
  submittedLabel: string | null;
  verifiedLabel: string | null;
  reviewNote: string | null;
  proofUploaded: boolean;
};

export type InvoiceInfo = {
  number: string;
  registration: string | null;
  trainingRequest: string | null;
  source: string;
  status: InvoiceStatus;
  statusLabel: string;
  awaitingVerification: boolean;
  payable: boolean;
  overdue: boolean;
  billTo: { name: string; email: string | null; company: string | null; address: string | null; taxId: string | null };
  items?: { description: string; quantity: number; unitPrice: number; amount: number }[];
  subtotal: number;
  discount: number;
  discountNote: string | null;
  taxRate: number;
  taxLabel: string | null;
  taxAmount: number;
  showTax: boolean;
  total: number;
  amountPaid: number;
  amountRefunded: number;
  outstanding: number;
  installmentNo: number;
  installmentTotal: number;
  description: string | null;
  notes: string | null;
  settlementNote: string | null;
  issuedAt: string | null;
  issuedLabel: string | null;
  dueDate: string | null;
  dueLabel: string | null;
  paidLabel: string | null;
  transactions?: InvoiceTransaction[];
  refunds?: { code: string; amount: number; status: string; statusLabel: string; completedLabel: string | null; reference: string | null }[];
  createdAt: string | null;
};

/** Rekening tujuan pembayaran manual (GET /me/payment-accounts). */
export type PaymentAccountInfo = {
  id: number;
  type: "bank_transfer" | "virtual_account";
  typeLabel: string;
  bank: string;
  number: string;
  name: string;
  instructions: string | null;
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
  invoices?: InvoiceInfo[];
};

/** Notifikasi aplikasi (GET /me/notifications, PRD §5.12). `url` = path portal tujuan. */
export type AppNotification = {
  id: string;
  key: string;
  category: string;
  title: string;
  body: string;
  url: string | null;
  read: boolean;
  createdAt: string;
};

export type NotificationCategoryPref = { key: string; label: string; description: string; mail: boolean; push: boolean };

export type NotificationSettings = {
  categories: NotificationCategoryPref[];
  push: { available: boolean; publicKey: string | null; devices: number };
  emailEnabled: boolean;
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
  certificate?: EnrollmentCertificate | null;
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

export const INVOICE_PILL: Record<InvoiceStatus | "awaiting", { label: string; cls: "ok" | "wn" | "no" }> = {
  pending: { label: "Belum dibayar", cls: "wn" },
  awaiting: { label: "Menunggu verifikasi", cls: "wn" },
  paid: { label: "Lunas", cls: "ok" },
  failed: { label: "Bukti ditolak", cls: "no" },
  expired: { label: "Kedaluwarsa", cls: "no" },
  refunded: { label: "Dikembalikan", cls: "no" },
  cancelled: { label: "Dibatalkan", cls: "no" },
};

