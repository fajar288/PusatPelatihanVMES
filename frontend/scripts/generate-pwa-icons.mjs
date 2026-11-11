// Ikon PWA dari emblem logo PPTI (public/images/logo.jpeg) → public/icons/*.png
// Jalankan: node scripts/generate-pwa-icons.mjs  (memakai sharp yang sudah terpasang sebagai dependensi Next)
import sharp from "sharp";

const SRC = "public/images/logo.jpeg";
// Area emblem heksagon di logo 1600×769 (tanpa tulisan "PPTI")
const EMBLEM = { left: 90, top: 30, width: 630, height: 690 };
const WHITE = { r: 255, g: 255, b: 255, alpha: 1 };

async function icon(size, file, padRatio) {
  const inner = Math.round(size * (1 - padRatio * 2));
  const emblem = await sharp(SRC).extract(EMBLEM).resize(inner, inner, { fit: "contain", background: WHITE }).toBuffer();
  await sharp({ create: { width: size, height: size, channels: 4, background: WHITE } })
    .composite([{ input: emblem, gravity: "center" }])
    .png({ compressionLevel: 9 })
    .toFile(`public/icons/${file}`);
  console.log("✓", file);
}

await icon(192, "icon-192.png", 0.06);
await icon(512, "icon-512.png", 0.06);
// maskable: konten di zona aman 80% (padding 10%+)
await icon(512, "maskable-512.png", 0.16);
await icon(180, "apple-touch-icon.png", 0.08);
await icon(96, "shortcut-96.png", 0.06);
