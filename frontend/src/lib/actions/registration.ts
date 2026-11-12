"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError, getToken } from "@/lib/auth";
import type { RegistrationInfo } from "@/lib/portal-types";

export type RegistrationState = { ok: boolean; message?: string; errors?: Record<string, string[]>; registration?: RegistrationInfo };

function fail(e: unknown): RegistrationState {
  if (e instanceof ApiRequestError) return { ok: false, message: e.error.message, errors: e.error.errors };
  return { ok: false, message: "Tidak dapat terhubung ke server. Coba lagi beberapa saat atau hubungi kami via WhatsApp." };
}

/** Form /daftar → POST /api/v1/registrations (tamu atau member login). */
export async function submitRegistrationAction(_prev: RegistrationState, form: FormData): Promise<RegistrationState> {
  const type = form.get("type") === "corporate" ? "corporate" : "individual";
  const participants = String(form.get("participants") ?? "")
    .split("\n")
    .map((l) => l.trim())
    .filter(Boolean)
    .map((line) => {
      const [name, email, position] = line.split(/\s*[,;|]\s*/);
      return { name, email: email || null, position: position || null };
    });
  const count = type === "corporate" ? Math.max(Number(form.get("participants_count") || 0), participants.length, 1) : 1;

  try {
    const token = await getToken();
    const r = await api<{ data: RegistrationInfo; message: string }>("/registrations", {
      method: "POST",
      token,
      json: {
        schedule: form.get("schedule"),
        type,
        name: form.get("name"),
        email: form.get("email"),
        phone: form.get("phone"),
        company: form.get("company") || null,
        position: form.get("position") || null,
        participants_count: count,
        participants: type === "corporate" ? participants : [],
        notes: form.get("notes") || null,
        consent: form.get("consent") === "on",
      },
    });
    revalidatePath("/portal");
    return { ok: true, message: r.message, registration: r.data };
  } catch (e) {
    return fail(e);
  }
}

export async function cancelRegistrationAction(number: string, reason: string): Promise<RegistrationState> {
  try {
    const token = await getToken();
    const r = await api<{ data: RegistrationInfo }>(`/me/registrations/${encodeURIComponent(number)}/cancel`, { method: "POST", token, json: { reason } });
    revalidatePath("/portal");
    return { ok: true, registration: r.data, message: "Pendaftaran dibatalkan." };
  } catch (e) {
    return fail(e);
  }
}
