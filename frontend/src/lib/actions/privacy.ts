"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError, getToken } from "@/lib/auth";
import type { FormState } from "./auth";

/** Ajukan penghapusan data pribadi: konfirmasi dengan email akun atau kata sandi, alasan opsional. */
export async function requestDeletionAction(_prev: FormState, form: FormData): Promise<FormState> {
  const method = form.get("confirm_by") === "password" ? "password" : "email";
  const confirmation = String(form.get("confirmation") ?? "").trim();
  if (!confirmation) return { ok: false, message: method === "password" ? "Masukkan kata sandi akun." : "Ketik alamat email akun Anda.", errors: { confirmation: ["Wajib diisi untuk konfirmasi."] } };
  if (form.get("understand") !== "on") return { ok: false, message: "Centang pernyataan bahwa Anda memahami data yang dihapus dan yang tetap disimpan." };
  try {
    const token = await getToken();
    const res = await api<{ data: { status: string; statusLabel: string } }>("/me/data-deletion", {
      method: "POST",
      token,
      json: { [method]: confirmation, reason: String(form.get("reason") ?? "").trim() || null },
    });
    revalidatePath("/portal/privasi");
    return res.data.status === "rejected"
      ? { ok: false, message: "Permintaan belum dapat diproses. Lihat alasannya di bawah." }
      : { ok: true, message: "Permintaan terkirim. Kami mengirim konfirmasi ke email Anda." };
  } catch (e) {
    if (e instanceof ApiRequestError) {
      const errors = e.error.errors ?? {};
      const first = errors.email?.[0] ?? errors.password?.[0] ?? errors.request?.[0] ?? e.error.message;
      return { ok: false, message: first, errors: { confirmation: errors.email ?? errors.password ?? [] } };
    }
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
}

export async function cancelDeletionAction(): Promise<FormState> {
  try {
    const token = await getToken();
    await api("/me/data-deletion/cancel", { method: "POST", token });
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.errors?.request?.[0] ?? e.error.message };
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
  revalidatePath("/portal/privasi");
  return { ok: true, message: "Permintaan dibatalkan." };
}
