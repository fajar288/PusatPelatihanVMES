"use server";

import { api, ApiRequestError, getToken } from "@/lib/auth";

export type AiFeedbackResult = { ok: true; helpful: boolean } | { ok: false; message: string };

/** Umpan balik "membantu / tidak membantu" untuk satu keluaran AI. Server menolak (404) insight yang tidak boleh dilihat. */
export async function aiFeedbackAction(insightId: number, helpful: boolean): Promise<AiFeedbackResult> {
  if (!Number.isInteger(insightId) || insightId <= 0) return { ok: false, message: "Data tidak valid." };
  try {
    const token = await getToken();
    const r = await api<{ data: { helpful: boolean } }>(`/ai-insights/${insightId}/feedback`, { method: "POST", token, json: { helpful } });
    return { ok: true, helpful: r.data.helpful };
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.message || "Umpan balik tidak dapat disimpan." };
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
}
