import { proxyFile } from "@/lib/file-proxy";

/** /trainer/tugas/berkas/[id] — berkas tugas peserta untuk dinilai trainer. */
export async function GET(_request: Request, { params }: RouteContext<"/trainer/tugas/berkas/[id]">) {
  const { id } = await params;
  return proxyFile(`/trainer/submissions/${encodeURIComponent(id)}/file`, "/trainer/tugas");
}
