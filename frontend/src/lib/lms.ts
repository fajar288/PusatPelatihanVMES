// LMS (PRD §5.8): tipe data dari /api/v1/me/learning/* dan /api/v1/trainer/classes/{code}/{learning,submissions,quiz-results}
// — lihat backend App\Support\LmsPresenter.

export type LessonType = "text" | "document" | "video" | "link" | "quiz" | "assignment";
export type ProgressStatus = "not_started" | "in_progress" | "completed";

export type CourseCard = {
  slug: string;
  title: string;
  description: string | null;
  thumbnail: string | null;
  modulesCount: number;
  lessonsCount: number;
  completed: number;
  percent: number;
  access: boolean;
  accessReason: string | null;
  enrollmentId: number;
  class: { code: string; title: string; dateLabel: string; endLabel: string; status: string };
};

export type LessonRow = {
  id: number;
  title: string;
  type: LessonType;
  typeLabel: string;
  durationMinutes: number | null;
  isRequired: boolean;
  available: boolean;
  availableFrom: string | null;
  status: ProgressStatus;
  completedAt: string | null;
};

export type ModuleTree = { id: number; title: string; description: string | null; lessonsCount: number; completed: number; isCompleted: boolean; lessons: LessonRow[] };

export type CourseDetail = CourseCard & {
  modules: ModuleTree[];
  nextLesson: { id: number; title: string; type: LessonType } | null;
  requiredTotal: number;
  requiredCompleted: number;
};

export type ResourceInfo = {
  id: number;
  title: string;
  type: "file" | "link";
  kind: "pdf" | "office" | "image" | "video" | "audio" | "file" | "link";
  fileName: string | null;
  size: number | null;
  sizeLabel: string | null;
  url: string | null;
  allowDownload: boolean;
};

export type QuizQuestionView = { id: number; type: "single" | "multiple" | "true_false" | "short"; question: string; options: { key: string; text: string }[]; points: number };

export type AttemptInfo = {
  id: number;
  attemptNo: number;
  score: number | null;
  maxScore: number | null;
  percent: number | null;
  passed: boolean | null;
  startedAt: string;
  submittedAt: string | null;
  submittedLabel: string | null;
  review?: {
    id: number;
    question: string;
    type: QuizQuestionView["type"];
    options: { key: string; text: string }[];
    given: string | string[] | null;
    answer: string[] | null;
    correct: boolean;
    points: number;
    maxPoints: number;
    explanation: string | null;
  }[];
};

export type QuizState = {
  kind: string;
  kindLabel: string;
  timeLimitMinutes: number | null;
  passingScore: number;
  maxAttempts: number | null;
  attemptsUsed: number;
  canStart: boolean;
  questionsCount: number;
  showAnswers: boolean;
  open: { id: number; attemptNo: number; startedAt: string; deadline: string | null; answers: Record<string, string | string[]>; questions: QuizQuestionView[] } | null;
  best: AttemptInfo | null;
  attempts: AttemptInfo[];
  result?: AttemptInfo;
};

export type SubmissionInfo = {
  id: number;
  text: string | null;
  fileName: string | null;
  hasFile: boolean;
  status: "submitted" | "graded" | "returned";
  statusLabel: string;
  submittedAt: string;
  submittedLabel: string;
  score: number | null;
  feedback: string | null;
  gradedAt: string | null;
  gradedBy: string | null;
};

export type LessonDetail = {
  id: number;
  title: string;
  type: LessonType;
  typeLabel: string;
  body: string | null;
  durationMinutes: number | null;
  isRequired: boolean;
  module: { id: number; title: string };
  course: { slug: string; title: string };
  prev: { id: number; title: string; type: LessonType } | null;
  next: { id: number; title: string; type: LessonType } | null;
  progress: { status: ProgressStatus; videoPosition: number; videoPercent: number; completedAt: string | null };
  resources: ResourceInfo[];
  video?: { url: string | null; embedUrl: string | null; provider: "youtube" | "vimeo" | "file" | null; durationSeconds: number | null; thumbnail: string | null; minWatchPct: number };
  link?: { url: string | null };
  quiz?: QuizState;
  assignment?: { dueAt: string | null; dueLabel: string | null; overdue: boolean; maxScore: number; allowFile: boolean; allowText: boolean; submission: SubmissionInfo | null };
};

export type DiscussionPostInfo = { id: number; body: string; author: string; isTrainer: boolean; isPinned: boolean; lessonId: number | null; createdAt: string; createdLabel: string; replies: DiscussionPostInfo[] };

export type AssignmentListItem = {
  lessonId: number;
  title: string;
  course: { slug: string; title: string };
  module: string;
  dueAt: string | null;
  dueLabel: string | null;
  overdue: boolean;
  maxScore: number;
  submission: SubmissionInfo | null;
};

export type ResultsInfo = {
  quizzes: { lessonId: number; title: string; kindLabel: string; course: { slug: string; title: string }; passingScore: number; attempts: number; best: AttemptInfo | null }[];
  assignments: { lessonId: number; title: string; course: { slug: string; title: string }; maxScore: number; submission: SubmissionInfo | null }[];
};

// ---- trainer ----
export type ClassProgressRow = {
  enrollment_id: number;
  name: string;
  email: string | null;
  status: string;
  attendance_percent: number | null;
  lessons_completed: number;
  lessons_total: number;
  percent: number;
  quiz_done: number;
  quiz_total: number;
  quiz_avg: number | null;
  assignments_submitted: number;
  assignments_total: number;
  assignments_graded: number;
  last_seen_at: string | null;
};

export type TrainerLearning = {
  class: { code: string; title: string };
  courses: { slug: string; title: string; description: string | null; availableBeforeClass: boolean; accessDaysAfter: number | null; lessonsCount: number; modules: { id: number; title: string; lessons: (LessonRow & { resources: ResourceInfo[] })[] }[] }[];
  participants: ClassProgressRow[];
};

export type TrainerSubmission = SubmissionInfo & { lessonId: number; lessonTitle: string; course: string; maxScore: number; participant: { id: number; name: string; email: string | null } };

export type TrainerSubmissions = {
  class: { code: string; title: string };
  participantsCount: number;
  assignments: { lessonId: number; title: string; course: string; maxScore: number; dueAt: string | null }[];
  submissions: TrainerSubmission[];
};

export type TrainerQuizResults = {
  class: { code: string; title: string };
  quizzes: { lessonId: number; title: string; course: string; kindLabel: string; passingScore: number; questionsCount: number }[];
  participants: { id: number; name: string; results: Record<string, { percent: number; passed: boolean; attempts: number; score: number; maxScore: number } | null> }[];
};

export const LESSON_ICON: Record<LessonType, string> = { text: "📄", document: "📚", video: "🎬", link: "🔗", quiz: "❓", assignment: "📝" };

export const PROGRESS_LABEL: Record<ProgressStatus, { label: string; cls: "ok" | "wn" | "no" }> = {
  not_started: { label: "Belum dibuka", cls: "no" },
  in_progress: { label: "Sedang dipelajari", cls: "wn" },
  completed: { label: "Selesai", cls: "ok" },
};

export const SUBMISSION_PILL: Record<SubmissionInfo["status"], "ok" | "wn" | "no"> = { submitted: "wn", graded: "ok", returned: "no" };
