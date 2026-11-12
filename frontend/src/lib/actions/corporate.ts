"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { api, ApiRequestError, getToken } from "@/lib/auth";
import type { CorporateRequest, ImportRow } from "@/lib/corporate";

export type CorpState = { ok: boolean; message?: string; errors?: Record<string, string[]>; report?: ImportRow[]; summary?: Record<string, number> };

const base = (org: string) => `/corporate/organizations/${encodeURIComponent(org)}`;

function fail(e: unknown): CorpState {
  if (e instanceof ApiRequestError) {
    const first = Object.values(e.error.errors ?? {})[0]?.[0];
    return { ok: false, message: first ?? e.error.message, errors: e.error.errors };
  }
  return { ok: false, message: "Tidak dapat terhubung ke server. Coba lagi beberapa saat." };
}

async function call<T>(path: string, init: { method?: string; json?: unknown; body?: FormData }): Promise<T> {
  const token = await getToken();
  return api<T>(path, { method: init.method ?? "POST", token, json: init.json, body: init.body });
}

const val = (f: FormData, k: string) => {
  const v = f.get(k);
  return typeof v === "string" && v.trim() !== "" ? v.trim() : null;
};

/** Buat permintaan training (draf atau langsung kirim ke VMES). */
export async function createRequestAction(_prev: CorpState, form: FormData): Promise<CorpState> {
  const org = String(form.get("org"));
  let code: string;
  try {
    const r = await call<{ data: CorporateRequest }>(`${base(org)}/requests`, {
      json: {
        package: form.get("package"),
        seats: Number(form.get("seats")),
        delivery: form.get("delivery"),
        target_date: val(form, "target_date"),
        location: val(form, "location"),
        unit_id: val(form, "unit_id") ? Number(form.get("unit_id")) : null,
        trainer_requirements: val(form, "trainer_requirements"),
        notes: val(form, "notes"),
        submit: form.get("intent") === "submit",
      },
    });
    code = r.data.code;
  } catch (e) {
    return fail(e);
  }
  revalidatePath(`/corporate/${org}`);
  redirect(`/corporate/${org}/permintaan/${code}`);
}

/** submit | accept-quote | approve | cancel */
export async function requestTransitionAction(org: string, code: string, action: "submit" | "accept-quote" | "approve" | "cancel", note?: string): Promise<CorpState> {
  try {
    await call(`${base(org)}/requests/${encodeURIComponent(code)}/${action}`, { json: { note: note ?? null } });
  } catch (e) {
    return fail(e);
  }
  revalidatePath(`/corporate/${org}`, "layout");
  const done: Record<typeof action, string> = { submit: "Permintaan dikirim ke VMES.", "accept-quote": "Penawaran diterima.", approve: "Permintaan disetujui.", cancel: "Permintaan dibatalkan." };
  return { ok: true, message: done[action] };
}

/** Tetapkan peserta ke kursi: member terverifikasi langsung ditetapkan, selain itu undangan dikirim ke email peserta. */
export async function assignSeatAction(_prev: CorpState, form: FormData): Promise<CorpState> {
  const org = String(form.get("org"));
  const code = String(form.get("code"));
  try {
    const r = await call<{ data: { status: string; message: string } }>(`${base(org)}/requests/${encodeURIComponent(code)}/seats/${form.get("seat")}/assign`, {
      json: {
        name: val(form, "name"),
        email: val(form, "email"),
        phone: val(form, "phone"),
        employee_id: val(form, "employee_id"),
        member_number: val(form, "member_number"),
        unit_id: val(form, "unit_id") ? Number(form.get("unit_id")) : null,
        position: val(form, "position"),
      },
    });
    revalidatePath(`/corporate/${org}/permintaan/${code}`);
    return { ok: r.data.status !== "review", message: r.data.message };
  } catch (e) {
    return fail(e);
  }
}

export async function assignEmployeeAction(_prev: CorpState, form: FormData): Promise<CorpState> {
  const org = String(form.get("org"));
  const code = String(form.get("code"));
  try {
    const r = await call<{ data: { message: string } }>(`${base(org)}/requests/${encodeURIComponent(code)}/seats/${form.get("seat")}/assign-employee`, {
      json: { employment_id: Number(form.get("employment_id")) },
    });
    revalidatePath(`/corporate/${org}/permintaan/${code}`);
    return { ok: true, message: r.data.message };
  } catch (e) {
    return fail(e);
  }
}

export async function releaseSeatAction(org: string, code: string, seat: number, reason: string): Promise<CorpState> {
  try {
    await call(`${base(org)}/requests/${encodeURIComponent(code)}/seats/${seat}/release`, { json: { reason } });
  } catch (e) {
    return fail(e);
  }
  revalidatePath(`/corporate/${org}/permintaan/${code}`);
  return { ok: true, message: "Peserta dibatalkan, kursi kembali kosong." };
}

/** Unggah massal CSV/XLSX → laporan per baris. */
export async function importParticipantsAction(_prev: CorpState, form: FormData): Promise<CorpState> {
  const org = String(form.get("org"));
  const code = String(form.get("code"));
  const file = form.get("file");
  if (!(file instanceof File) || file.size === 0) return { ok: false, message: "Pilih berkas CSV atau XLSX." };
  const body = new FormData();
  body.append("file", file);
  try {
    const r = await call<{ data: ImportRow[]; summary: Record<string, number> }>(`${base(org)}/requests/${encodeURIComponent(code)}/import`, { body });
    revalidatePath(`/corporate/${org}/permintaan/${code}`);
    return { ok: true, report: r.data, summary: r.summary, message: "Berkas diproses." };
  } catch (e) {
    return fail(e);
  }
}

export async function addCorporateUserAction(_prev: CorpState, form: FormData): Promise<CorpState> {
  const org = String(form.get("org"));
  try {
    await call(`${base(org)}/users`, { json: { email: val(form, "email"), role: form.get("role"), unit_id: val(form, "unit_id") ? Number(form.get("unit_id")) : null } });
  } catch (e) {
    return fail(e);
  }
  revalidatePath(`/corporate/${org}/pengguna`);
  return { ok: true, message: "Pengguna ditambahkan." };
}

export async function updateCorporateUserAction(org: string, id: number, role: string, unitId: number | null): Promise<CorpState> {
  try {
    await call(`${base(org)}/users/${id}`, { method: "PATCH", json: { role, unit_id: unitId } });
  } catch (e) {
    return fail(e);
  }
  revalidatePath(`/corporate/${org}/pengguna`);
  return { ok: true, message: "Peran diperbarui." };
}

export async function disableCorporateUserAction(org: string, id: number): Promise<CorpState> {
  try {
    await call(`${base(org)}/users/${id}/disable`, {});
  } catch (e) {
    return fail(e);
  }
  revalidatePath(`/corporate/${org}/pengguna`);
  return { ok: true, message: "Akses dicabut." };
}

// ---- verifikasi email & undangan (akun peserta) ----

export async function resendVerificationAction(): Promise<CorpState> {
  try {
    const r = await call<{ message: string }>("/auth/email/resend", {});
    return { ok: true, message: r.message };
  } catch (e) {
    return fail(e);
  }
}

export async function acceptInvitationAction(_prev: CorpState, form: FormData): Promise<CorpState> {
  const token = String(form.get("token"));
  try {
    await call(`/invitations/${encodeURIComponent(token)}/accept`, { json: { consent: form.get("consent") === "on" } });
  } catch (e) {
    return fail(e);
  }
  revalidatePath("/portal", "layout");
  redirect("/portal/pelatihan?undangan=diterima");
}
