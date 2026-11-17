"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError, getToken } from "@/lib/auth";
import type { FormState } from "./auth";

/** Kirim jawaban survei evaluasi. Field form: `id`, `q_<key>` per pertanyaan. */
export async function submitSurveyAction(_prev: FormState, form: FormData): Promise<FormState> {
  const id = Number(form.get("id"));
  if (!Number.isInteger(id) || id <= 0) return { ok: false, message: "Survei tidak valid." };
  const answers: Record<string, string> = {};
  for (const [k, v] of form.entries()) {
    if (k.startsWith("q_") && typeof v === "string") answers[k.slice(2)] = v;
  }
  try {
    const token = await getToken();
    await api(`/me/surveys/${id}`, { method: "POST", token, json: { answers } });
    revalidatePath("/portal/survei");
    return { ok: true, message: "Terima kasih, survei Anda sudah terkirim." };
  } catch (e) {
    if (e instanceof ApiRequestError) {
      // answers.overall → q_overall agar pesan tampil di bawah pertanyaannya.
      const errors = e.error.errors ? Object.fromEntries(Object.entries(e.error.errors).map(([k, v]) => [k.replace(/^answers\./, "q_"), v])) : undefined;
      return { ok: false, message: e.error.message, errors };
    }
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
}
