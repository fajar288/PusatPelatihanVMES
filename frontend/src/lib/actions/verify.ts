"use server";

import { api, ApiRequestError } from "@/lib/auth";

export type NameCheckState = { done: boolean; matches?: boolean; message?: string; name?: string };

/** Cocokkan nama yang tertera di sertifikat dengan penerima (sertifikat dengan nama disamarkan). Tidak pernah mengembalikan nama. */
export async function checkCertificateNameAction(number: string, _prev: NameCheckState, form: FormData): Promise<NameCheckState> {
  const name = String(form.get("name") ?? "").trim();
  if (name.length < 2) return { done: false, message: "Ketik nama seperti tertulis di sertifikat.", name };
  try {
    const r = await api<{ data: { matches: boolean } }>(`/verify/${encodeURIComponent(number)}/nama`, { method: "POST", json: { name } });
    return { done: true, matches: r.data.matches, name };
  } catch (e) {
    if (e instanceof ApiRequestError) return { done: false, message: e.error.status === 429 ? "Terlalu banyak percobaan. Coba lagi dalam satu menit." : e.error.message };
    return { done: false, message: "Tidak dapat terhubung ke server." };
  }
}
