"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError, getToken } from "@/lib/auth";

export type InvoiceProofState = { ok: boolean; message?: string; errors?: Record<string, string[]> };

/**
 * Unggah bukti transfer untuk satu invoice (Roadmap 5C). `scope` = "me" (portal member) atau kode organisasi (portal
 * perusahaan, peran finance/owner). Status invoice hanya berubah setelah diverifikasi finance VMES.
 */
export async function uploadInvoiceProofAction(_prev: InvoiceProofState, form: FormData): Promise<InvoiceProofState> {
  const scope = String(form.get("scope") ?? "me");
  const number = String(form.get("number") ?? "");
  const file = form.get("proof");
  if (!number) return { ok: false, message: "Invoice tidak dikenali." };
  if (!(file instanceof File) || file.size === 0) return { ok: false, message: "Pilih berkas bukti transfer (JPG/PNG/PDF maks. 4 MB)." };
  const body = new FormData();
  body.set("proof", file, file.name);
  for (const key of ["amount", "transfer_date", "payment_account_id", "payer_name", "note"]) {
    const v = String(form.get(key) ?? "").trim();
    if (v) body.set(key, key === "amount" ? v.replace(/[^\d]/g, "") : v);
  }
  const path =
    scope === "me"
      ? `/me/invoices/${encodeURIComponent(number)}/proof`
      : `/corporate/organizations/${encodeURIComponent(scope)}/invoices/${encodeURIComponent(number)}/proof`;
  try {
    const token = await getToken();
    await api(path, { method: "POST", token, body });
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.message, errors: e.error.errors };
    return { ok: false, message: "Tidak dapat terhubung ke server. Coba lagi beberapa saat." };
  }
  if (scope === "me") {
    revalidatePath("/portal", "layout");
  } else {
    revalidatePath(`/corporate/${scope}`, "layout");
  }
  return { ok: true, message: "Bukti pembayaran terkirim dan menunggu verifikasi tim finance VMES." };
}
