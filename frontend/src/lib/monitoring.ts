// Training Logbook & Progress Monitoring (PRD §5.9): tipe data dari /api/v1/trainer/classes/{code}/{logbooks|assessments|progress} dan /me/progress.
import type { ClassProgressRow } from "./lms";

export type ClassInfo = { code: string; title: string; dateLabel: string; endLabel: string; status: string };

export type PracticeStatus = "completed" | "partial" | "not_done";

export type LogbookEntryInfo = { enrollmentId: number; practiceStatus: PracticeStatus | null; practiceLabel: string | null; observation: string | null };

export type LogbookInfo = {
  id: number;
  sessionId: number | null;
  sessionNo: number | null;
  date: string;
  dateLabel: string;
  topic: string;
  activities: string | null;
  competencies: string[];
  practice: string | null;
  observations: string | null;
  obstacles: string | null;
  notes: string | null;
  documentation: { index: number; name: string; mime: string | null; size: number | null }[];
  status: "draft" | "validated";
  statusLabel: string;
  isLocked: boolean;
  trainer: string | null;
  validatedAt: string | null;
  validatedBy: string | null;
  updatedAt: string | null;
  entries: LogbookEntryInfo[];
};

export type LogbooksData = {
  class: ClassInfo;
  sessions: { id: number; no: number; date: string; dateLabel: string; topic: string | null; hasLogbook: boolean }[];
  participants: { id: number; name: string; email: string | null; status: string }[];
  practiceLabels: Record<PracticeStatus, string>;
  logbooks: LogbookInfo[];
};

export type RubricLevel = { score: number; label: string; description?: string };

export type CriterionInfo = {
  id: number;
  code: string | null;
  name: string;
  competency: string | null;
  description: string | null;
  weight: number;
  maxScore: number;
  levels: RubricLevel[];
};

export type CriterionScore = CriterionInfo & { score: number | null; levelLabel: string | null; note: string | null };

export type AssessmentInfo = {
  id: number;
  status: "draft" | "validated";
  statusLabel: string;
  isLocked: boolean;
  percent: number | null;
  isCompetent: boolean | null;
  threshold: number;
  summary: string | null;
  recommendation: string | null;
  assessedAt: string | null;
  assessedLabel: string | null;
  assessedBy: string | null;
  validatedAt: string | null;
  validatedBy: string | null;
  rubric: { id: number; name: string; description: string | null };
  criteria: CriterionScore[];
};

export type AssessmentsData = {
  class: ClassInfo;
  rubric: ({ id: number; name: string; description: string | null; threshold: number; criteria: CriterionInfo[] }) | null;
  participants: { participant: { id: number; name: string; email: string | null; status: string }; assessment: AssessmentInfo }[];
};

export type OverviewRow = ClassProgressRow & {
  practice_completed: number;
  practice_partial: number;
  practice_not_done: number;
  notes_count: number;
  competency_percent: number | null;
  competency_status: "draft" | "validated" | null;
  is_competent: boolean | null;
};

export type ClassOverview = { class: ClassInfo; participants: OverviewRow[] };

export type ParticipantOverview = {
  enrollment: { id: number; name: string; email: string | null; status: string; finalScore: number | null };
  class: ClassInfo;
  attendance: { percent: number | null; present: number; held: number; total: number };
  learning: { percent: number; completed: number; total: number; courses: { slug: string; title: string; percent: number; completed: number; total: number }[]; lastSeenAt: string | null };
  scores: { quizAvg: number | null; quizDone: number; quizTotal: number; assignmentAvg: number | null; assignmentsGraded: number; assignmentsSubmitted: number; assignmentsTotal: number };
  practice: { completed: number; partial: number; notDone: number; logged: number };
  competency: AssessmentInfo | null;
  notes: { logbookId: number; date: string; dateLabel: string; topic: string; status: string; trainer: string | null; practiceStatus: PracticeStatus | null; practiceLabel: string | null; observation: string | null }[];
  logbooks: { id: number; date: string; dateLabel: string; topic: string; competencies: string[]; status: string }[];
};

export const PRACTICE_PILL: Record<PracticeStatus, "ok" | "wn" | "no"> = { completed: "ok", partial: "wn", not_done: "no" };

export const ENROLLMENT_STATUS: Record<string, string> = { enrolled: "Terdaftar", attending: "Mengikuti", completed: "Selesai", failed: "Tidak lulus", dropped: "Mengundurkan diri" };

export function pct(v: number | null | undefined, suffix = "%"): string {
  return v === null || v === undefined ? "-" : `${v}${suffix}`;
}
