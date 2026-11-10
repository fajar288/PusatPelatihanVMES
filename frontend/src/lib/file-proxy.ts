import { NextResponse } from "next/server";
import { getToken } from "./auth";

const API = (process.env.API_URL_INTERNAL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");

/**
 * Meneruskan berkas dari API (butuh token Sanctum yang hanya ada di cookie httpOnly) ke browser.
 * Dipakai route handler unduh materi/tugas; header Content-Type & Content-Disposition diteruskan apa adanya.
 */
export async function proxyFile(apiPath: string, loginNext: string): Promise<Response> {
  const token = await getToken();
  if (!token) return NextResponse.redirect(new URL(`/login?next=${encodeURIComponent(loginNext)}`, process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000"));
  const upstream = await fetch(`${API}${apiPath}`, { headers: { Authorization: `Bearer ${token}`, Accept: "*/*" }, cache: "no-store" });
  if (!upstream.ok) {
    let message = `Berkas tidak tersedia (${upstream.status}).`;
    try {
      message = ((await upstream.json()) as { message?: string }).message ?? message;
    } catch {
      /* bukan JSON */
    }
    return new NextResponse(message, { status: upstream.status, headers: { "Content-Type": "text/plain; charset=utf-8" } });
  }
  const headers = new Headers();
  for (const h of ["content-type", "content-disposition", "content-length", "cache-control"]) {
    const v = upstream.headers.get(h);
    if (v) headers.set(h, v);
  }
  return new NextResponse(upstream.body, { status: 200, headers });
}
