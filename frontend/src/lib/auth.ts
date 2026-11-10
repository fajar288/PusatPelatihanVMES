// Sesi portal member: token Sanctum disimpan di cookie httpOnly, dipakai hanya di server (server components & actions).
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import type { PortalUser } from "./portal-types";

export const TOKEN_COOKIE = "ppti_token";
const API = (process.env.API_URL_INTERNAL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");

export type ApiError = { status: number; message: string; errors: Record<string, string[]> };

export class ApiRequestError extends Error {
  constructor(public readonly error: ApiError) {
    super(error.message);
  }
}

/** Panggil API Laravel; `token` opsional untuk endpoint terautentikasi. Melempar ApiRequestError untuk respons non-2xx. */
export async function api<T>(path: string, init: RequestInit & { token?: string | null; json?: unknown } = {}): Promise<T> {
  const { token, json, headers, ...rest } = init;
  const h: Record<string, string> = { Accept: "application/json", ...(headers as Record<string, string>) };
  if (token) h.Authorization = `Bearer ${token}`;
  if (json !== undefined) h["Content-Type"] = "application/json";
  const res = await fetch(`${API}${path}`, { ...rest, headers: h, body: json !== undefined ? JSON.stringify(json) : rest.body, cache: "no-store" });
  const text = await res.text();
  let data: unknown = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = null;
  }
  if (!res.ok) {
    const d = (data ?? {}) as { message?: string; errors?: Record<string, string[]> };
    throw new ApiRequestError({ status: res.status, message: d.message ?? `Permintaan gagal (${res.status})`, errors: d.errors ?? {} });
  }
  return data as T;
}

export async function getToken(): Promise<string | null> {
  return (await cookies()).get(TOKEN_COOKIE)?.value ?? null;
}

/** Pengguna saat ini (null bila belum login atau token tidak valid). */
export async function getCurrentUser(): Promise<PortalUser | null> {
  const token = await getToken();
  if (!token) return null;
  try {
    const r = await api<{ data: PortalUser }>("/me", { token });
    return r.data;
  } catch {
    return null;
  }
}

/** Wajib login: redirect ke /login?next= bila belum. */
export async function requireUser(next = "/portal"): Promise<{ user: PortalUser; token: string }> {
  const token = await getToken();
  const user = token ? await getCurrentUser() : null;
  if (!token || !user) redirect(`/login?next=${encodeURIComponent(next)}`);
  return { user, token };
}
