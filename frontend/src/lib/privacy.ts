// Permintaan penghapusan data pribadi (GET /me/data-deletion). Tipe murni: aman diimpor komponen klien.

export type DeletionIssue = { code: string; message: string };

export type DeletionRequestInfo = {
  id: number;
  status: "pending" | "approved" | "rejected" | "completed" | "cancelled";
  statusLabel: string;
  autoRejected: boolean;
  reason: string | null;
  adminNote: string | null;
  blockers: DeletionIssue[];
  requestedAt: string | null;
  requestedLabel: string | null;
  dueLabel: string | null;
  processedLabel: string | null;
  cancellable: boolean;
};

export type DeletionInfo = {
  request: DeletionRequestInfo | null;
  canRequest: boolean;
  blockers: DeletionIssue[];
  warnings: DeletionIssue[];
  deadlineDays: number;
  policy: { deleted: string[]; kept: { item: string; reason: string }[] };
};

export const DELETION_PILL: Record<DeletionRequestInfo["status"], "ok" | "wn" | "no"> = {
  pending: "wn",
  approved: "wn",
  completed: "ok",
  rejected: "no",
  cancelled: "no",
};
