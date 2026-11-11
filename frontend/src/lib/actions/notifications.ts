"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { api, ApiRequestError, getToken } from "@/lib/auth";
import type { NotificationSettings } from "@/lib/portal-types";
import type { FormState } from "./auth";

/** Hanya path internal portal (cegah open redirect lewat data notifikasi). */
function safePath(url: FormDataEntryValue | null): string {
  const s = typeof url === "string" ? url : "";
  return s.startsWith("/") && !s.startsWith("//") ? s : "/portal/notifikasi";
}

/** Buka notifikasi: tandai dibaca lalu menuju halaman terkait. */
export async function openNotificationAction(form: FormData): Promise<void> {
  const token = await getToken();
  const id = String(form.get("id") ?? "");
  if (token && /^[0-9a-f-]{36}$/i.test(id)) {
    try {
      await api(`/me/notifications/${id}/read`, { method: "POST", token });
    } catch {
      /* tetap arahkan ke tujuan */
    }
  }
  revalidatePath("/portal", "layout");
  redirect(safePath(form.get("url")));
}

export async function readAllNotificationsAction(): Promise<void> {
  const token = await getToken();
  if (token) await api("/me/notifications/read-all", { method: "POST", token });
  revalidatePath("/portal", "layout");
}

export async function saveNotificationPrefsAction(_prev: FormState, form: FormData): Promise<FormState> {
  const categories = String(form.get("categories") ?? "").split(",").filter(Boolean);
  const preferences = Object.fromEntries(categories.map((c) => [c, { mail: form.get(`mail_${c}`) === "on", push: form.get(`push_${c}`) === "on" }]));
  try {
    await api<{ data: NotificationSettings }>("/me/notification-settings", { method: "PUT", token: await getToken(), json: { preferences } });
    return { ok: true, message: "Pengaturan notifikasi disimpan." };
  } catch (e) {
    if (e instanceof ApiRequestError) return { ok: false, message: e.error.message, errors: e.error.errors };
    return { ok: false, message: "Tidak dapat terhubung ke server." };
  }
}

type PushResult = { ok: boolean; message: string; devices?: number };

function pushError(e: unknown): PushResult {
  return { ok: false, message: e instanceof ApiRequestError ? e.error.message : "Tidak dapat terhubung ke server." };
}

/** Simpan langganan PushManager (PushSubscription.toJSON()) untuk akun ini. */
export async function subscribePushAction(sub: { endpoint?: string; keys?: Record<string, string> }): Promise<PushResult> {
  try {
    const r = await api<{ data: { devices: number }; message: string }>("/me/push-subscriptions", {
      method: "POST",
      token: await getToken(),
      json: { endpoint: sub.endpoint, keys: { p256dh: sub.keys?.p256dh, auth: sub.keys?.auth }, contentEncoding: "aes128gcm" },
    });
    return { ok: true, message: r.message, devices: r.data.devices };
  } catch (e) {
    return pushError(e);
  }
}

export async function unsubscribePushAction(endpoint: string): Promise<PushResult> {
  try {
    const r = await api<{ data: { devices: number } }>("/me/push-subscriptions", { method: "DELETE", token: await getToken(), json: { endpoint } });
    return { ok: true, message: "Notifikasi push dimatikan di perangkat ini.", devices: r.data.devices };
  } catch (e) {
    return pushError(e);
  }
}

export async function testPushAction(): Promise<PushResult> {
  try {
    await api("/me/push-subscriptions/test", { method: "POST", token: await getToken() });
    return { ok: true, message: "Notifikasi uji dikirim. Periksa perangkat Anda." };
  } catch (e) {
    return pushError(e);
  }
}
