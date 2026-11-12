import { proxyFile } from "@/lib/file-proxy";

/** /corporate/[org]/permintaan/[code]/template — template CSV peserta via API terautentikasi. */
export async function GET(_request: Request, { params }: RouteContext<"/corporate/[org]/permintaan/[code]/template">) {
  const { org, code } = await params;
  return proxyFile(`/corporate/organizations/${encodeURIComponent(org)}/requests/${encodeURIComponent(code)}/import-template`, `/corporate/${org}/permintaan/${code}`);
}
