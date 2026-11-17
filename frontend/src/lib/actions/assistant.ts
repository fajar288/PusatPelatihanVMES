"use server";

import { api, ApiRequestError, getToken } from "@/lib/auth";
import type { AssistantMessage } from "@/lib/portal-types";

export type AskResult = { ok: true; message: AssistantMessage } | { ok: false; code: string; message: string; field?: string };

/** Kirim satu pertanyaan ke Asisten Belajar. Server memeriksa akses course, kuota harian, dan status fitur. */
export async function askAssistantAction(course: string, question: string, lessonId: number | null): Promise<AskResult> {
  const q = question.trim();
  if (!course) return { ok: false, code: "invalid", message: "Pilih course terlebih dahulu.", field: "course" };
  if (q.length < 3) return { ok: false, code: "invalid", message: "Tulis pertanyaan minimal 3 karakter.", field: "question" };
  if (q.length > 1000) return { ok: false, code: "invalid", message: "Pertanyaan maksimal 1000 karakter.", field: "question" };
  try {
    const token = await getToken();
    const r = await api<{ data: AssistantMessage }>("/me/assistant/messages", { method: "POST", token, json: { course, question: q, lessonId } });
    return { ok: true, message: r.data };
  } catch (e) {
    if (e instanceof ApiRequestError) {
      const field = e.error.errors ? Object.keys(e.error.errors)[0] : undefined;
      return { ok: false, code: e.error.code ?? String(e.error.status), message: e.error.message || "Pertanyaan tidak dapat dikirim.", field };
    }
    return { ok: false, code: "network", message: "Tidak dapat terhubung ke server." };
  }
}
