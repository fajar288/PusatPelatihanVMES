// Sertifikat & alumni (PRD §5.10, §5.6): tipe data dari /api/v1/me/certificates dan /api/v1/verify/{number}.

export type CertificateType = "participation" | "competency" | "vendor";

export type CertificateStatus = "draft" | "pending_validation" | "issued" | "revoked" | "replaced";

export type CertificateInfo = {
  id: number;
  number: string;
  type: CertificateType;
  typeLabel: string;
  status: CertificateStatus;
  statusLabel: string;
  isValid: boolean;
  isExpired: boolean;
  recipientName: string;
  programTitle: string;
  classCode: string | null;
  periodLabel: string | null;
  city: string | null;
  hours: number | null;
  score: number | null;
  predicate: string | null;
  competencies: string[];
  issuer: string | null;
  signatory: string | null;
  signatoryTitle: string | null;
  issuedAt: string | null;
  issuedLabel: string | null;
  validUntil: string | null;
  validUntilLabel: string | null;
  revokedAt: string | null;
  revokeReason: string | null;
  replacedBy: string | null;
  replaces: string | null;
  verificationUrl: string;
  downloadable: boolean;
  enrollmentId: number;
  createdAt: string | null;
};

export type CertificateHistory = { action: string; actor: string; at: string | null; atLabel: string | null; after: Record<string, unknown> | null };

export type VerificationResult = {
  number: string;
  status: CertificateStatus | "expired" | "pending";
  statusLabel: string;
  valid: boolean;
  codeMatches: boolean | null;
  type: CertificateType;
  typeLabel: string;
  recipientName: string | null;
  programTitle: string;
  classCode: string | null;
  periodLabel: string | null;
  city: string | null;
  hours: number | null;
  predicate: string | null;
  issuer: string | null;
  signatory: string | null;
  issuedAt: string | null;
  issuedLabel: string | null;
  validUntil: string | null;
  validUntilLabel: string | null;
  revokedAt: string | null;
  replacedBy: string | null;
  competencies: string[];
};

/** Ringkasan sertifikat yang ikut pada /me/enrollments. */
export type EnrollmentCertificate = { number: string; status: CertificateStatus; statusLabel: string; typeLabel: string; issuedLabel: string | null; downloadable: boolean; verificationUrl: string };

export const CERT_PILL: Record<string, "ok" | "wn" | "no"> = { issued: "ok", expired: "wn", pending: "wn", pending_validation: "wn", draft: "wn", revoked: "no", replaced: "no" };

export const HISTORY_LABEL: Record<string, string> = {
  "certificate.created": "Draf dibuat",
  "certificate.submitted": "Diajukan validasi",
  "certificate.issued": "Diterbitkan",
  "certificate.revoked": "Dicabut",
  "certificate.replaced": "Diganti",
  "certificate.regenerated": "PDF dibuat ulang",
  "certificate.downloaded": "Diunduh",
};

export const MEMBER_STATUS: Record<string, string> = { registered: "Terdaftar", active: "Peserta aktif", alumni: "Alumni", inactive: "Tidak aktif" };
