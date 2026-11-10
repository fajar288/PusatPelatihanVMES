"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError, getToken } from "@/lib/auth";
import type { FormState } from "./auth";

/** Tandai kehadiran satu sesi: field `att_<enrollmentId>` = present|absent|late|excused, `note_<id>` opsional. */
export async function markAttendanceAction(_prev: FormState, form: FormData): Promise<FormState> {
  const code = String(form.get("code") ?? "");
  const no = String(form.get("session_no") ?? "");
  const marks: { enrollment_id: number; status: string; note?: string }[] = [];
  for (const [k, v] of form.entries()) {
    if (k.startsWith("att_") && typeof v === "string" && v) {
      const id = Number(k.slice(4));
      const note = form.get(`note_${id}`);
      marks.push({ enrollment_id: id, status: v, note: typeof note === "string" && note ? note : undefined });
    }
  }
  if (!marks.length) return { ok: false, message: "Pilih status kehadiran minimal satu peserta." };
  try {
    const token = await getToken();
    const r = await api<{ marked: number }>(`/trainer/classes/${encodeURIComponent(code)}/sessions/${encodeURIComponent(no)}/attendance`, { method: "POST", token, json: { marks } });
    revalidatePath(`/trainer/kelas/${code}`);
    return { ok: true, message: `Kehadiran ${r.marked} peserta tersimpan.` };
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.message, errors: e.error.errors };
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
}

export async function updateTrainerProfileAction(_prev: FormState, form: FormData): Promise<FormState> {
  const list = (v: FormDataEntryValue | null) => String(v ?? "").split(/\r?\n|,/).map((s) => s.trim()).filter(Boolean);
  try {
    const token = await getToken();
    await api("/trainer/profile", {
      method: "PATCH",
      token,
      json: {
        title: form.get("title") || null,
        phone: form.get("phone") || null,
        expertise: list(form.get("expertise")),
        certifications: list(form.get("certifications")),
        education: form.get("education") || null,
        experience: form.get("experience") || null,
        portfolio: form.get("portfolio") || null,
        bio: form.get("bio") || null,
      },
    });
    revalidatePath("/trainer/profil");
    return { ok: true, message: "Profil trainer tersimpan." };
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.message, errors: e.error.errors };
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
}
