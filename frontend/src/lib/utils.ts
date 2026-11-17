import type { SiteSettings } from "@/lib/cms-types";

/** Format "Rp 7.500.000" persis seperti website lama (spasi setelah Rp). */
export function formatRupiah(n: number): string {
  return "Rp " + n.toLocaleString("id-ID");
}

/** Bagian pengaturan situs yang dibutuhkan tautan WhatsApp. */
export type WaContact = Pick<SiteSettings, "waNumber" | "waDefaultText">;

/**
 * Link WhatsApp dengan teks prefill. Nomor & pesan pembuka dari pengaturan CMS (getSiteSettings() di server,
 * useSite() di klien), bukan dari data statis. Tanpa teks → pesan pembuka situs.
 */
export function waLink(site: WaContact, text?: string): string {
  return `https://wa.me/${site.waNumber}?text=${encodeURIComponent(text || site.waDefaultText)}`;
}

/** Teks prefill tombol "Daftar" per jadwal: "Halo, saya ingin mendaftar training X (tanggal)". */
export function waRegisterText(title: string, dateLabel: string): string {
  return `Halo, saya ingin mendaftar training ${title} (${dateLabel})`;
}
