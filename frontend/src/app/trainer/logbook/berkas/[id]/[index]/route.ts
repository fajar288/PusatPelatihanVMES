import { proxyFile } from "@/lib/file-proxy";

/** /trainer/logbook/berkas/[id]/[index] — dokumentasi logbook (foto/PDF/video) untuk trainer kelas tersebut. */
export async function GET(_request: Request, { params }: RouteContext<"/trainer/logbook/berkas/[id]/[index]">) {
  const { id, index } = await params;
  return proxyFile(`/trainer/logbooks/${encodeURIComponent(id)}/docs/${encodeURIComponent(index)}`, "/trainer/logbook");
}
