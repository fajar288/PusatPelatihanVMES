import { proxyFile } from "@/lib/file-proxy";

/** /portal/belajar/tugas-berkas/[id] — berkas tugas yang dikumpulkan peserta sendiri. */
export async function GET(_request: Request, { params }: RouteContext<"/portal/belajar/tugas-berkas/[id]">) {
  const { id } = await params;
  return proxyFile(`/me/learning/submissions/${encodeURIComponent(id)}/file`, "/portal/tugas");
}
