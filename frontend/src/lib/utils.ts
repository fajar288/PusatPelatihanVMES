import { SITE } from "@/data/site";

/** Format "Rp 7.500.000" persis seperti website lama (spasi setelah Rp). */
export function formatRupiah(n: number): string {
  return "Rp " + n.toLocaleString("id-ID");
}

/** Link WhatsApp dengan teks prefill. Tanpa argumen → teks default situs. */
export function waLink(text: string = SITE.waDefaultText): string {
  return `https://wa.me/${SITE.waNumber}?text=${encodeURIComponent(text)}`;
}

/** Teks prefill tombol "Daftar" per jadwal: "Halo, saya ingin mendaftar training X (tanggal)". */
export function waRegisterText(title: string, dateLabel: string): string {
  return `Halo, saya ingin mendaftar training ${title} (${dateLabel})`;
}
