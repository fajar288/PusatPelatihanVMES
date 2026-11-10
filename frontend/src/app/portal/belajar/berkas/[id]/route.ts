import { proxyFile } from "@/lib/file-proxy";

/** /portal/belajar/berkas/[id]?inline=1 — berkas materi (viewer/unduh) via API terautentikasi. */
export async function GET(request: Request, { params }: RouteContext<"/portal/belajar/berkas/[id]">) {
  const { id } = await params;
  const inline = new URL(request.url).searchParams.get("inline") === "1";
  return proxyFile(`/me/learning/resources/${encodeURIComponent(id)}${inline ? "?inline=1" : ""}`, "/portal/belajar");
}
