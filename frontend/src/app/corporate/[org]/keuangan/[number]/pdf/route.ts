import { proxyFile } from "@/lib/file-proxy";

/** /corporate/[org]/keuangan/[number]/pdf — PDF invoice perusahaan lewat API terautentikasi. */
export async function GET(_request: Request, { params }: RouteContext<"/corporate/[org]/keuangan/[number]/pdf">) {
  const { org, number } = await params;
  return proxyFile(`/corporate/organizations/${encodeURIComponent(org)}/invoices/${encodeURIComponent(number)}/pdf`, `/corporate/${org}/keuangan/${number}`);
}
