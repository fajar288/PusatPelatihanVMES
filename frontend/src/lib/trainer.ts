// Portal trainer (PRD §5.7): tipe & pemuat data dari /api/v1/trainer/*.
import { redirect } from "next/navigation";
import { api, ApiRequestError, requireUser } from "./auth";

export type TrainerProfile = {
  id: number;
  name: string;
  slug: string;
  title: string | null;
  email: string | null;
  phone: string | null;
  photo: string | null;
  expertise: string[];
  education: string | null;
  experience: string | null;
  certifications: string[];
  portfolio: string | null;
  bio: string | null;
  isAssessor: boolean;
};

export type TrainerClass = {
  code: string;
  title: string;
  packageSlug: string | null;
  startDate: string;
  endDate: string;
  dateLabel: string;
  endLabel: string;
  time: string | null;
  city: string | null;
  location: string | null;
  mode: string;
  status: string;
  quota: number | null;
  participantsCount: number;
  sessionsCount: number;
  myRole: string | null;
  trainers: { name: string; role: string }[];
  sessions: { no: number; date: string; dateLabel: string; topic: string | null; time: string | null; marked: number | null }[];
  participants?: { id: number; name: string; email: string | null; status: string; attendancePercent: number | null; attendance: Record<string, string | null> }[];
  attendanceLabels?: Record<string, string>;
};

export const CLASS_STATUS: Record<string, string> = {
  draft: "Draft", registration_open: "Pendaftaran dibuka", registration_closed: "Pendaftaran ditutup", confirmed: "Terkonfirmasi", ongoing: "Berlangsung", completed: "Selesai", cancelled: "Dibatalkan",
};

export const ROLE_LABEL: Record<string, string> = { lead: "Trainer utama", assistant: "Asisten", assessor: "Assessor" };

/** Wajib login + punya profil trainer aktif; selain itu diarahkan ke portal member. */
export async function requireTrainer(next = "/trainer"): Promise<{ token: string; profile: TrainerProfile }> {
  const { token } = await requireUser(next);
  try {
    const r = await api<{ data: TrainerProfile }>("/trainer/profile", { token });
    return { token, profile: r.data };
  } catch (e) {
    if (e instanceof ApiRequestError && e.error.status === 403) redirect("/portal?trainer=0");
    throw e;
  }
}
