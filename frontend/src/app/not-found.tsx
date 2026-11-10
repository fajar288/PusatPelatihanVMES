import type { Metadata } from "next";
import Link from "next/link";
import { SITE } from "@/data/site";

export const metadata: Metadata = { title: `Halaman tidak ditemukan | ${SITE.name}` };

/** 404 — memakai gaya `.nores` dari tema asli (teks tengah, tombol hijau). */
export default function NotFound() {
  return (
    <div className="wrap">
      <div className="nores">
        <h2>Halaman tidak ditemukan</h2>
        <p>Halaman yang Anda cari tidak tersedia atau sudah dipindahkan.</p>
        <Link className="btn" href="/">
          Kembali ke Beranda
        </Link>
      </div>
    </div>
  );
}
