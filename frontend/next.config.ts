import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  /* config options here */
  // Build standalone untuk image Docker (frontend/Dockerfile)
  output: "standalone",
  cacheComponents: true,
  images: {
    // Logo & partner: sumber PNG/JPEG besar dioptimalkan on-demand (AVIF → WebP → asli)
    formats: ["image/avif", "image/webp"],
    minimumCacheTTL: 60 * 60 * 24 * 30,
  },
  partialPrefetching: true,
  experimental: {
    // PWA (Phase 5): deteksi offline + navigasi/aksi server yang gagal ditahan lalu diulang saat online (hook useOffline)
    useOffline: true,
    serverActions: {
      // Bawaan Next 1 MB terlalu kecil untuk unggahan lewat server action: tugas LMS maks. 20 MB, dokumentasi logbook
      // maks. 10 MB per berkas, dokumen pendaftaran 5 x 4 MB, bukti bayar 4 MB. Ditambah ruang untuk overhead multipart.
      bodySizeLimit: "25mb",
    },
  },
  async headers() {
    return [
      {
        // Header keamanan dasar semua halaman. CSP penuh belum dipasang karena situs memuat embed YouTube/Maps dan
        // foto hotlink; yang dibatasi: dibingkai situs lain (clickjacking), base URI, dan plugin.
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "X-Frame-Options", value: "SAMEORIGIN" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=(), payment=()" },
          { key: "Content-Security-Policy", value: "frame-ancestors 'self'; base-uri 'self'; object-src 'none'" },
          ...(process.env.NODE_ENV === "production" ? [{ key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" }] : []),
        ],
      },
      {
        // Service worker selalu diambil ulang (tanpa cache HTTP) agar pembaruan segera terdeteksi
        source: "/sw.js",
        headers: [
          { key: "Content-Type", value: "application/javascript; charset=utf-8" },
          { key: "Cache-Control", value: "no-cache, no-store, must-revalidate" },
          { key: "Content-Security-Policy", value: "default-src 'self'; script-src 'self'" },
          { key: "Service-Worker-Allowed", value: "/" },
        ],
      },
    ];
  },
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
