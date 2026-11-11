import { proxyFile } from "@/lib/file-proxy";

/** /portal/sertifikat/unduh/[number]?inline=1 — PDF sertifikat via API terautentikasi (token di cookie httpOnly). */
export async function GET(request: Request, { params }: RouteContext<"/portal/sertifikat/unduh/[number]">) {
  const { number } = await params;
  const inline = new URL(request.url).searchParams.get("inline") === "1";
  const res = await proxyFile(`/me/certificates/${encodeURIComponent(number)}/pdf`, "/portal/sertifikat");
  if (inline && res.ok) {
    const headers = new Headers(res.headers);
    headers.set("content-disposition", (headers.get("content-disposition") ?? "").replace(/^attachment/, "inline") || "inline");
    return new Response(res.body, { status: res.status, headers });
  }
  return res;
}
