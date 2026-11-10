"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError, getToken } from "@/lib/auth";
import type { QuizState, SubmissionInfo } from "@/lib/lms";
import type { FormState } from "./auth";

type Result<T> = { ok: true; data: T } | { ok: false; message: string; errors?: Record<string, string[]> };

async function call<T>(path: string, init: { method?: string; json?: unknown; body?: FormData }): Promise<Result<T>> {
  try {
    const token = await getToken();
    const r = await api<{ data: T }>(path, { method: init.method ?? "POST", token, json: init.json, body: init.body });
    return { ok: true, data: r.data };
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.message, errors: e.error.errors };
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
}

const lessonPath = (course: string, lesson: number) => `/me/learning/${encodeURIComponent(course)}/lessons/${lesson}`;

/** Tandai lesson selesai (teks/dokumen/tautan/video). */
export async function completeLessonAction(course: string, lesson: number): Promise<Result<{ status: string }>> {
  const r = await call<{ status: string }>(`${lessonPath(course, lesson)}/progress`, { json: { action: "complete" } });
  if (r.ok) revalidatePath(`/portal/belajar/${course}`, "layout");
  return r;
}

/** Posisi/persentase video (resume + tracking). Tidak me-revalidate agar player tidak di-remount. */
export async function videoProgressAction(course: string, lesson: number, position: number, percent: number, spent: number): Promise<Result<{ status: string; videoPercent: number }>> {
  return call(`${lessonPath(course, lesson)}/progress`, { json: { action: "video", position, percent, spent } });
}

export async function startQuizAction(course: string, lesson: number): Promise<Result<QuizState>> {
  return call<QuizState>(`${lessonPath(course, lesson)}/quiz/start`, {});
}

export async function submitQuizAction(course: string, lesson: number, answers: Record<string, string | string[]>): Promise<Result<QuizState>> {
  const r = await call<QuizState>(`${lessonPath(course, lesson)}/quiz/submit`, { json: { answers } });
  if (r.ok) revalidatePath(`/portal/belajar/${course}`, "layout");
  return r;
}

/** Kumpulkan tugas: field `course`, `lesson`, `text`, `file`. */
export async function submitAssignmentAction(_prev: FormState, form: FormData): Promise<FormState> {
  const course = String(form.get("course") ?? "");
  const lesson = Number(form.get("lesson"));
  const body = new FormData();
  const text = form.get("text");
  if (typeof text === "string" && text.trim()) body.set("text", text);
  const file = form.get("file");
  if (file instanceof File && file.size > 0) body.set("file", file, file.name);
  if (!body.has("text") && !body.has("file")) return { ok: false, message: "Isi jawaban atau unggah berkas." };
  const r = await call<SubmissionInfo>(`${lessonPath(course, lesson)}/assignment`, { body });
  if (!r.ok) return { ok: false, message: r.message, errors: r.errors };
  revalidatePath(`/portal/belajar/${course}`, "layout");
  revalidatePath("/portal/tugas");
  return { ok: true, message: "Tugas berhasil dikumpulkan." };
}

/** Kirim pertanyaan/balasan forum: field `course`, `lesson` (opsional), `parent` (opsional), `body`, `back` (path untuk revalidate). */
export async function postDiscussionAction(_prev: FormState, form: FormData): Promise<FormState> {
  const course = String(form.get("course") ?? "");
  const body = String(form.get("body") ?? "").trim();
  if (!body) return { ok: false, message: "Tulis pesan terlebih dahulu." };
  const lesson = form.get("lesson");
  const parent = form.get("parent");
  const r = await call(`/me/learning/${encodeURIComponent(course)}/discussions`, {
    json: { body, lesson_id: lesson ? Number(lesson) : null, parent_id: parent ? Number(parent) : null },
  });
  if (!r.ok) return { ok: false, message: r.message, errors: r.errors };
  const back = form.get("back");
  if (typeof back === "string" && back) revalidatePath(back);
  return { ok: true, message: "Pesan terkirim." };
}

/** Trainer: nilai / kembalikan tugas. field `id`, `status`, `score`, `feedback`, `code`. */
export async function gradeSubmissionAction(_prev: FormState, form: FormData): Promise<FormState> {
  const id = Number(form.get("id"));
  const status = String(form.get("status") ?? "graded");
  const score = form.get("score");
  const r = await call<SubmissionInfo>(`/trainer/submissions/${id}/grade`, {
    json: { status, score: typeof score === "string" && score !== "" ? Number(score) : null, feedback: form.get("feedback") || null },
  });
  if (!r.ok) return { ok: false, message: r.message, errors: r.errors };
  revalidatePath(`/trainer/tugas/${form.get("code") ?? ""}`);
  return { ok: true, message: status === "graded" ? `Nilai ${r.data.score} tersimpan.` : "Tugas dikembalikan ke peserta untuk revisi." };
}
