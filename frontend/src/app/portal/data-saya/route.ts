import { proxyFile } from "@/lib/file-proxy";

/** /portal/data-saya: unduh salinan data pribadi milik akun (JSON) lewat API terautentikasi. */
export async function GET() {
  return proxyFile("/me/data-export", "/portal/privasi");
}
