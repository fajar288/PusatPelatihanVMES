import type { MetadataRoute } from "next";
import { SITE } from "@/data/site";

/** Web App Manifest (PWA, Phase 5) — dapat dipasang di layar utama; pintasan ke halaman yang paling sering dibuka. */
export default function manifest(): MetadataRoute.Manifest {
  const shortcutIcon = [{ src: "/icons/shortcut-96.png", sizes: "96x96", type: "image/png" }];
  return {
    id: "/",
    name: SITE.name,
    short_name: "PPTI VMES",
    description: SITE.description,
    lang: "id",
    dir: "ltr",
    start_url: "/?source=pwa",
    scope: "/",
    display: "standalone",
    display_override: ["standalone", "minimal-ui"],
    orientation: "portrait",
    background_color: "#ffffff",
    theme_color: "#004B23",
    categories: ["education", "business", "productivity"],
    icons: [
      { src: "/icons/icon-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
      { src: "/icons/icon-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
      { src: "/icons/maskable-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
    ],
    shortcuts: [
      { name: "Jadwal Training", short_name: "Jadwal", url: "/jadwal?source=pwa", icons: shortcutIcon },
      { name: "Katalog Kursus", short_name: "Kursus", url: "/kursus?source=pwa", icons: shortcutIcon },
      { name: "Portal Member", short_name: "Portal", url: "/portal?source=pwa", icons: shortcutIcon },
      { name: "Verifikasi Sertifikat", short_name: "Verifikasi", url: "/verifikasi?source=pwa", icons: shortcutIcon },
    ],
  };
}
