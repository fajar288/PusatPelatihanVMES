import { proxyFile } from "@/lib/file-proxy";

/** /portal/pembayaran/[number]/pdf — PDF invoice lewat API terautentikasi (token di cookie httpOnly). */
export async function GET(_request: Request, { params }: RouteContext<"/portal/pembayaran/[number]/pdf">) {
  const { number } = await params;
  return proxyFile(`/me/invoices/${encodeURIComponent(number)}/pdf`, `/portal/pembayaran/${number}`);
}
