"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError, getToken } from "@/lib/auth";
import type { RegistrationInfo } from "@/lib/portal-types";

export type RegistrationState = { ok: boolean; message?: string; errors?: Record<string, string[]>; registration?: RegistrationInfo };

function fail(e: unknown): RegistrationState {
  if (e instanceof ApiRequestError) {
    // galat per berkas (documents.0, documents.1, ...) ditampilkan bersama di bawah input dokumen
    const errors: Record<string, string[]> = {};
    for (const [k, v] of Object.entries(e.error.errors ?? {})) {
      const key = k.startsWith("documents.") ? "documents" : k;
      errors[key] = [...(errors[key] ?? []), ...v];
    }
    return { ok: false, message: e.error.message, errors };
  }
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
  const documents = documentFiles(form);
  const tooMany = checkDocuments(documents);
  if (tooMany) return { ok: false, message: tooMany, errors: { documents: [tooMany] } };

  // multipart agar dokumen pendukung ikut terkirim; field kosong tidak dikirim (backend menganggapnya null)
  const body = new FormData();
  const put = (k: string, v: FormDataEntryValue | null | undefined) => {
    const s = typeof v === "string" ? v.trim() : "";
    if (s) body.set(k, s);
  };
  put("schedule", form.get("schedule"));
  body.set("type", type);
  for (const k of ["name", "email", "phone", "company", "position", "notes"]) put(k, form.get(k));
  body.set("participants_count", String(count));
  if (type === "corporate") {
    participants.forEach((p, i) => {
      put(`participants[${i}][name]`, p.name);
      put(`participants[${i}][email]`, p.email);
      put(`participants[${i}][position]`, p.position);
    });
  }
  if (form.get("consent") === "on") body.set("consent", "1");
  documents.forEach((f) => body.append("documents[]", f, f.name));

  try {
    const token = await getToken();
    const r = await api<{ data: RegistrationInfo; message: string }>("/registrations", { method: "POST", token, body });
    revalidatePath("/portal");
    return { ok: true, message: r.message, registration: r.data };
  } catch (e) {
    return fail(e);
  }
}

/** Batas dokumen pendukung, sama dengan validasi backend (Registration::MAX_DOCUMENTS & DOCUMENT_MAX_KB). */
const MAX_DOCUMENTS = 5;
const MAX_DOCUMENT_BYTES = 4 * 1024 * 1024;

function documentFiles(form: FormData): File[] {
  return form.getAll("documents").filter((f): f is File => f instanceof File && f.size > 0);
}

/** Pesan galat bila jumlah/ukuran berkas melampaui batas (dicek sebelum dikirim agar pesannya jelas). */
function checkDocuments(files: File[], existing = 0): string | null {
  if (files.length + existing > MAX_DOCUMENTS) return `Maksimal ${MAX_DOCUMENTS} dokumen per pendaftaran${existing ? ` (sudah ada ${existing})` : ""}.`;
  const big = files.find((f) => f.size > MAX_DOCUMENT_BYTES);
  return big ? `Berkas "${big.name}" lebih dari 4 MB.` : null;
}

/** Portal member: tambah dokumen pendukung ke pendaftaran yang belum terkonfirmasi. */
export async function uploadRegistrationDocumentsAction(_prev: RegistrationState, form: FormData): Promise<RegistrationState> {
  const number = String(form.get("number") ?? "");
  const files = documentFiles(form);
  if (!number) return { ok: false, message: "Pendaftaran tidak dikenali." };
  if (files.length === 0) return { ok: false, message: "Pilih minimal satu berkas (PDF, JPG, PNG, DOC, atau DOCX)." };
  const problem = checkDocuments(files, Number(form.get("existing") || 0));
  if (problem) return { ok: false, message: problem };
  const body = new FormData();
  files.forEach((f) => body.append("documents[]", f, f.name));
  try {
    const token = await getToken();
    const r = await api<{ data: RegistrationInfo }>(`/me/registrations/${encodeURIComponent(number)}/documents`, { method: "POST", token, body });
    revalidatePath(`/portal/pendaftaran/${number}`);
    return { ok: true, registration: r.data, message: files.length > 1 ? `${files.length} dokumen terunggah.` : "Dokumen terunggah." };
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
