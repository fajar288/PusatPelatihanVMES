"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError, getToken } from "@/lib/auth";
import type { AssessmentInfo, LogbookInfo } from "@/lib/monitoring";
import type { FormState } from "./auth";

type Result<T> = { ok: true; data: T } | { ok: false; message: string; errors?: Record<string, string[]> };

async function call<T>(path: string, init: { json?: unknown; body?: FormData }): Promise<Result<T>> {
  try {
    const token = await getToken();
    const r = await api<{ data: T }>(path, { method: "POST", token, json: init.json, body: init.body });
    return { ok: true, data: r.data };
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.errors && Object.values(e.error.errors)[0]?.[0] ? Object.values(e.error.errors)[0][0] : e.error.message, errors: e.error.errors };
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
}

/**
 * Simpan logbook (buat / ubah). Field: `code`, `id` (kosong = baru), `session_id`, `date`, `topic`, `activities`, `competencies` (dipisah koma/baris),
 * `practice`, `observations`, `obstacles`, `notes`, `ps_<enrollmentId>` (status praktik), `ob_<enrollmentId>` (observasi), `docs` (berkas), `remove_docs` (indeks, bisa banyak).
 */
export async function saveLogbookAction(_prev: FormState, form: FormData): Promise<FormState> {
  const code = String(form.get("code") ?? "");
  const id = String(form.get("id") ?? "");
  const body = new FormData();
  for (const k of ["session_id", "date", "topic", "activities", "practice", "observations", "obstacles", "notes"]) {
    const v = form.get(k);
    if (typeof v === "string" && v !== "") body.set(k, v);
  }
  String(form.get("competencies") ?? "")
    .split(/\r?\n|,/)
    .map((s) => s.trim())
    .filter(Boolean)
    .forEach((c, i) => body.set(`competencies[${i}]`, c));
  let n = 0;
  for (const [k, v] of form.entries()) {
    if (!k.startsWith("ps_")) continue;
    const eid = k.slice(3);
    const ob = form.get(`ob_${eid}`);
    const ps = typeof v === "string" ? v : "";
    const obs = typeof ob === "string" ? ob.trim() : "";
    body.set(`entries[${n}][enrollment_id]`, eid);
    if (ps) body.set(`entries[${n}][practice_status]`, ps);
    if (obs) body.set(`entries[${n}][observation]`, obs);
    n++;
  }
  let d = 0;
  for (const f of form.getAll("docs")) {
    if (f instanceof File && f.size > 0) body.append(`docs[${d++}]`, f, f.name);
  }
  form.getAll("remove_docs").forEach((i, k) => body.set(`remove_docs[${k}]`, String(i)));
  const r = await call<LogbookInfo>(id ? `/trainer/logbooks/${id}` : `/trainer/classes/${encodeURIComponent(code)}/logbooks`, { body });
  if (!r.ok) return { ok: false, message: r.message, errors: r.errors };
  revalidatePath(`/trainer/logbook/${code}`);
  revalidatePath(`/trainer/progres/${code}`, "layout");
  return { ok: true, message: id ? "Logbook diperbarui." : "Logbook tersimpan sebagai draf. Validasi setelah data lengkap." };
}

export async function validateLogbookAction(id: number, code: string): Promise<Result<LogbookInfo>> {
  const r = await call<LogbookInfo>(`/trainer/logbooks/${id}/validate`, {});
  if (r.ok) {
    revalidatePath(`/trainer/logbook/${code}`);
    revalidatePath(`/trainer/progres/${code}`, "layout");
  }
  return r;
}

/** Simpan penilaian kompetensi satu peserta. Field: `code`, `enrollment`, `sc_<criterionId>`, `nt_<criterionId>`, `summary`, `recommendation`, `validate` (1 = validasi & kunci). */
export async function saveAssessmentAction(_prev: FormState, form: FormData): Promise<FormState> {
  const code = String(form.get("code") ?? "");
  const enrollment = String(form.get("enrollment") ?? "");
  const scores: { criterion_id: number; score: number | null; note: string | null }[] = [];
  for (const [k, v] of form.entries()) {
    if (!k.startsWith("sc_")) continue;
    const cid = Number(k.slice(3));
    const note = form.get(`nt_${cid}`);
    scores.push({ criterion_id: cid, score: typeof v === "string" && v !== "" ? Number(v) : null, note: typeof note === "string" && note.trim() ? note.trim() : null });
  }
  if (!scores.length) return { ok: false, message: "Rubrik belum memiliki kriteria." };
  const validate = form.get("validate") === "1";
  const r = await call<AssessmentInfo>(`/trainer/classes/${encodeURIComponent(code)}/assessments/${enrollment}`, {
    json: { scores, summary: form.get("summary") || null, recommendation: form.get("recommendation") || null, validate },
  });
  if (!r.ok) return { ok: false, message: r.message, errors: r.errors };
  revalidatePath(`/trainer/penilaian/${code}`);
  revalidatePath(`/trainer/progres/${code}`, "layout");
  return { ok: true, message: validate ? `Penilaian divalidasi & dikunci (${r.data.percent ?? "-"}%).` : `Draf penilaian tersimpan${r.data.percent !== null ? ` (${r.data.percent}%)` : ""}.` };
}
