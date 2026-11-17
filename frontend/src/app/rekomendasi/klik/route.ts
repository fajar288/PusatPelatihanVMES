import { NextResponse } from "next/server";
import { api, getToken } from "@/lib/auth";

/**
 * /rekomendasi/klik?i=<insight>&p=<slug program>&to=<path internal>: catat klik rekomendasi (untuk CTR) lalu teruskan
 * ke halaman tujuan. Pencatatan gagal (misalnya sesi habis) tidak menghalangi pengguna membuka halaman program.
 */
export async function GET(request: Request) {
  const url = new URL(request.url);
  const id = Number(url.searchParams.get("i"));
  const slug = url.searchParams.get("p") ?? "";
  const raw = url.searchParams.get("to") ?? "";
  // Hanya path internal (cegah open redirect).
  const to = raw.startsWith("/") && !raw.startsWith("//") && !raw.includes("\\") ? raw : `/kursus/${encodeURIComponent(slug)}`;
  const token = await getToken();
  if (token && Number.isInteger(id) && id > 0 && /^[a-z0-9-]{1,120}$/.test(slug)) {
    try {
      await api(`/ai-insights/${id}/click`, { method: "POST", token, json: { package: slug } });
    } catch {
      /* tetap teruskan */
    }
  }
  return NextResponse.redirect(new URL(to, url.origin));
}
