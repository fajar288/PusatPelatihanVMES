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

/** Unggah/ganti foto profil (JPG/PNG/WebP maks. 4 MB; server memotong persegi dan mengompres ke WebP). */
export async function uploadPhotoAction(_prev: FormState, form: FormData): Promise<FormState> {
  const file = form.get("photo");
  if (!(file instanceof File) || file.size === 0) return { ok: false, message: "Pilih foto (JPG, PNG, atau WebP)." };
  if (file.size > 4 * 1024 * 1024) return { ok: false, message: "Ukuran foto maksimal 4 MB." };
  const body = new FormData();
  body.set("photo", file, file.name);
  try {
    const token = await getToken();
    await api("/me/photo", { method: "POST", token, body });
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.errors?.photo?.[0] ?? e.error.message, errors: e.error.errors };
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
  revalidatePath("/portal", "layout");
  return { ok: true, message: "Foto profil diperbarui." };
}

export async function removePhotoAction(): Promise<FormState> {
  try {
    const token = await getToken();
    await api("/me/photo", { method: "DELETE", token });
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.message };
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
  revalidatePath("/portal", "layout");
  return { ok: true, message: "Foto profil dihapus." };
}
