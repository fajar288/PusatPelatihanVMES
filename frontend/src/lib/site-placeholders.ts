import type { SiteSettings } from "@/lib/cms-types";
import { waLink } from "@/lib/utils";

const esc = (s: string) => s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

/**
 * Placeholder kontak di isi halaman CMS, diisi saat render dari pengaturan situs agar nomor tidak tertulis di konten:
 * - `{{whatsapp}}`: tautan WhatsApp dengan tampilan nomor (mis. 0851-2125-1285)
 * - `{{alamat}}`  : alamat kantor
 * Admin bisa memakainya saat menulis halaman di panel (lihat petunjuk di form Halaman).
 */
export function fillSitePlaceholders(html: string, site: SiteSettings): string {
  return html
    .replace(/\{\{\s*whatsapp\s*\}\}/gi, `<a href="${esc(waLink(site))}" target="_blank" rel="noopener">${esc(site.waDisplay)}</a>`)
    .replace(/\{\{\s*alamat\s*\}\}/gi, esc(site.address));
}
