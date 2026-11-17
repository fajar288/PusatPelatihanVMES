/** Survei evaluasi pasca-kelas (portal member & trainer). */
export type SurveyQuestion = { key: string; type: "scale" | "comment"; label: string; required: boolean };

export type SurveyRow = {
  id: number;
  status: "pending" | "submitted";
  statusLabel: string;
  class: { code: string | null; title: string | null; endLabel: string | null };
  invitedAt: string | null;
  submittedAt: string | null;
};

export type SurveyDetail = SurveyRow & { questions: SurveyQuestion[]; answers: Record<string, string | number> };

export type SurveyClassReport = {
  class: { code: string; title: string; dateLabel: string; endLabel: string };
  report: {
    invited: number;
    submitted: number;
    responseRate: number | null;
    average: number | null;
    questions: { key: string; label: string; average: number | null; count: number }[];
    comments: { question: string; text: string }[];
    detailHidden: boolean;
    minResponses: number;
  };
  summary: {
    insightId: number;
    aiGenerated: true;
    summary: string;
    strengths: string[];
    improvements: string[];
    actions: string[];
    computedAt: string | null;
    myFeedback: boolean | null;
  } | null;
  aiStatus: boolean;
};

export const SCALE_LABELS: Record<number, string> = { 1: "Sangat kurang", 2: "Kurang", 3: "Cukup", 4: "Baik", 5: "Sangat baik" };

export const fmtScore = (v: number | null) => (v === null ? "-" : `${v.toLocaleString("id-ID", { maximumFractionDigits: 2 })} / 5`);
