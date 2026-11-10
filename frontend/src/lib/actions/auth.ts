"use server";

import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { api, ApiRequestError, getToken, TOKEN_COOKIE } from "@/lib/auth";
import type { PortalUser } from "@/lib/portal-types";

export type FormState = { ok: boolean; message?: string; errors?: Record<string, string[]> };

const COOKIE_DAYS = 30;

async function setSession(token: string): Promise<void> {
  (await cookies()).set(TOKEN_COOKIE, token, {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env.NODE_ENV === "production",
    path: "/",
    maxAge: COOKIE_DAYS * 86400,
  });
}

const safeNext = (v: FormDataEntryValue | null) => (typeof v === "string" && v.startsWith("/") && !v.startsWith("//") ? v : "/portal");

function fail(e: unknown): FormState {
  if (e instanceof ApiRequestError) return { ok: false, message: e.error.message, errors: e.error.errors };
  return { ok: false, message: "Tidak dapat terhubung ke server. Coba lagi beberapa saat." };
}

export async function loginAction(_prev: FormState, form: FormData): Promise<FormState> {
  let next = "/portal";
  try {
    next = safeNext(form.get("next"));
    const r = await api<{ token: string; user: PortalUser }>("/auth/login", {
      method: "POST",
      json: { email: form.get("email"), password: form.get("password"), device: "web" },
    });
    await setSession(r.token);
  } catch (e) {
    return fail(e);
  }
  redirect(next);
}

export async function registerAction(_prev: FormState, form: FormData): Promise<FormState> {
  let next = "/portal";
  try {
    next = safeNext(form.get("next"));
    const r = await api<{ token: string; user: PortalUser }>("/auth/register", {
      method: "POST",
      json: {
        name: form.get("name"),
        email: form.get("email"),
        password: form.get("password"),
        password_confirmation: form.get("password_confirmation"),
        phone: form.get("phone"),
        company: form.get("company") || null,
        position: form.get("position") || null,
        city: form.get("city") || null,
        consent: form.get("consent") === "on",
        device: "web",
      },
    });
    await setSession(r.token);
  } catch (e) {
    return fail(e);
  }
  redirect(next);
}

export async function logoutAction(): Promise<void> {
  const token = await getToken();
  if (token) {
    try {
      await api("/auth/logout", { method: "POST", token });
    } catch {
      /* token mungkin sudah kedaluwarsa */
    }
  }
  (await cookies()).delete(TOKEN_COOKIE);
  redirect("/login");
}

export async function forgotPasswordAction(_prev: FormState, form: FormData): Promise<FormState> {
  try {
    const r = await api<{ message: string }>("/auth/forgot-password", { method: "POST", json: { email: form.get("email") } });
    return { ok: true, message: r.message };
  } catch (e) {
    return fail(e);
  }
}

export async function resetPasswordAction(_prev: FormState, form: FormData): Promise<FormState> {
  try {
    await api("/auth/reset-password", {
      method: "POST",
      json: { token: form.get("token"), email: form.get("email"), password: form.get("password"), password_confirmation: form.get("password_confirmation") },
    });
    return { ok: true, message: "Sandi berhasil diubah. Silakan masuk dengan sandi baru." };
  } catch (e) {
    return fail(e);
  }
}
