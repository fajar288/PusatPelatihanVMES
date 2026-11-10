"use server";

import { api, ApiRequestError, getToken } from "@/lib/auth";

export type WaitlistState = { ok: boolean; message?: string; errors?: Record<string, string[]>; position?: number };

/** Form daftar tunggu di detail jadwal (kelas penuh) → POST /api/v1/schedules/{code}/waitlist. */
export async function joinWaitlistAction(_prev: WaitlistState, form: FormData): Promise<WaitlistState> {
  const code = String(form.get("schedule") ?? "");
  try {
    const token = await getToken();
    const r = await api<{ data: { position: number }; message: string }>(`/schedules/${encodeURIComponent(code)}/waitlist`, {
      method: "POST",
      token,
      json: { name: form.get("name"), email: form.get("email"), phone: form.get("phone") || null, participants_count: Number(form.get("participants_count") || 1), notes: form.get("notes") || null },
    });
    return { ok: true, message: r.message, position: r.data.position };
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.message, errors: e.error.errors };
    return { ok: false, message: "Tidak dapat terhubung ke server. Hubungi kami via WhatsApp." };
  }
}
