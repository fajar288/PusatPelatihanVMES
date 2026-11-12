import { proxyFile } from "@/lib/file-proxy";

/** /corporate/[org]/peserta/ekspor — CSV peserta bersponsor (izin & scope sama dengan dashboard, diaudit di API). */
export async function GET(_request: Request, { params }: RouteContext<"/corporate/[org]/peserta/ekspor">) {
  const { org } = await params;
  return proxyFile(`/corporate/organizations/${encodeURIComponent(org)}/enrollments-export`, `/corporate/${org}/peserta`);
}
