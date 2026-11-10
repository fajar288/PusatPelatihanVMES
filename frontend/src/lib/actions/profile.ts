"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError, getToken } from "@/lib/auth";
import type { FormState } from "./auth";

export async function updateProfileAction(_prev: FormState, form: FormData): Promise<FormState> {
  try {
    const token = await getToken();
    await api("/me", {
      method: "PATCH",
      token,
      json: {
        name: form.get("name"),
        phone: form.get("phone") || null,
        birth_date: form.get("birth_date") || null,
        gender: form.get("gender") || null,
        company: form.get("company") || null,
        position: form.get("position") || null,
        education: form.get("education") || null,
        address: form.get("address") || null,
        city: form.get("city") || null,
        communication_pref: { email: form.get("pref_email") === "on", whatsapp: form.get("pref_whatsapp") === "on" },
      },
    });
    revalidatePath("/portal");
    return { ok: true, message: "Profil tersimpan." };
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.message, errors: e.error.errors };
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
}
