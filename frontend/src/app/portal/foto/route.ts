import { proxyFile } from "@/lib/file-proxy";

/** /portal/foto?v=...: foto profil member (disimpan privat) lewat API terautentikasi; `v` hanya untuk cache-busting. */
export async function GET() {
  return proxyFile("/me/photo", "/portal/profil");
}
