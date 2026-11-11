import type { Metadata } from "next";
import Link from "next/link";
import { SITE } from "@/data/site";

export const metadata: Metadata = { title: `Offline | ${SITE.name}`, robots: { index: false } };

/** Halaman cadangan service worker (PWA) saat perangkat offline dan halaman belum pernah dibuka. Statis → di-precache. */
export default function OfflinePage() {
  return (
    <div className="wrap">
      <div className="nores">
        <h2>Anda sedang offline</h2>
        <p>Halaman ini belum tersimpan di perangkat. Periksa koneksi internet Anda, lalu coba lagi.</p>
        <p style={{ fontSize: 14 }}>Halaman publik yang pernah Anda buka (beranda, kursus, jadwal, artikel) tetap bisa dibaca tanpa koneksi.</p>
        <Link className="btn" href="/">
          Ke Beranda
        </Link>
      </div>
    </div>
  );
}
