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
  },
  async headers() {
    return [
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
