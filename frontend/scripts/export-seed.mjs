// Ekspor data dummy frontend (src/data/*.ts) → backend/database/seeders/data/*.json
// Jalankan dari folder frontend:  node --experimental-strip-types scripts/export-seed.mjs
// Satu sumber kebenaran: konten contoh di frontend menjadi seed awal database CMS.
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const out = resolve(root, "../backend/database/seeders/data");
const load = (rel) => import(pathToFileURL(resolve(root, rel)).href);
mkdirSync(out, { recursive: true });

const site = await load("src/data/site.ts");
const home = await load("src/data/home.ts");
const about = await load("src/data/about.ts");
const courses = await load("src/data/courses.ts");
const schedules = await load("src/data/schedules.ts");
const articles = await load("src/data/articles.ts");
const pages = await load("src/data/pages.ts");
const testimonials = await load("src/data/testimonials.ts");

const slugify = (s) =>
  s.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/&/g, " dan ").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");

const { blocksToHtml } = await load("src/lib/blocks.ts"); // implementasi bersama dengan fallback frontend

const iso = (unix) => new Date(unix * 1000).toISOString();
const modeOf = (m) => (m === "online / offline" ? "online_offline" : m);

// ---- Kategori: 8 area kompetensi (beranda, parent) + 5 kategori paket (anak dari "IT, AI & Data Analytics")
const parentAreas = home.CATEGORIES.map((c, i) => ({ slug: slugify(c.title), name: c.title, description: c.desc, icon: c.icon, sort_order: i + 1, show_on_home: true, parent_slug: null }));
const leafParent = "it-ai-dan-data-analytics";
const leafCats = courses.COURSE_CATEGORIES.map((name, i) => ({ slug: slugify(name), name, description: null, icon: null, sort_order: 100 + i, show_on_home: false, parent_slug: leafParent }));
writeFileSync(resolve(out, "categories.json"), JSON.stringify([...parentAreas, ...leafCats], null, 2));

// ---- Paket training
const packages = courses.COURSES.map((c, i) => ({
  slug: c.slug, code: null, title: c.title, category_slug: slugify(c.category), vendor: c.vendor, excerpt: c.desc,
  overview_intro: c.overview.intro, learning_outcomes: c.overview.learn, audience: c.overview.audience, requirements: c.overview.requirements,
  duration_days: c.durationDays, duration_hours: c.durationDays * 8, level: c.level, mode: modeOf(c.mode), price: c.price, quota: 16,
  image: c.image ?? null, certificate: c.certificate, is_popular: !!c.popular, availability: c.status === "ok" ? "available" : "coming_soon",
  status: "published", published_at: iso(c.publishedAt), sort_order: i + 1,
}));
// Jadwal yang paketnya belum ada di katalog (CCNP, Linux) → paket draft berkategori agar relasi & filter tetap benar
for (const s of schedules.SCHEDULES) {
  if (packages.some((p) => p.slug === s.courseSlug)) continue;
  packages.push({
    slug: s.courseSlug, code: null, title: s.title, category_slug: slugify(s.category), vendor: null, excerpt: null,
    overview_intro: [], learning_outcomes: [], audience: [], requirements: [], duration_days: s.durationDays, duration_hours: s.durationDays * 8,
    level: null, mode: modeOf(s.mode), price: s.price, quota: s.quota, image: null, certificate: null, is_popular: false,
    availability: "coming_soon", status: "draft", published_at: null, sort_order: 100 + packages.length,
  });
}
writeFileSync(resolve(out, "packages.json"), JSON.stringify(packages, null, 2));

// ---- Jadwal / batch
const statusMap = { ok: "registration_open", wn: "registration_open", no: "registration_closed" };
const sched = schedules.SCHEDULES.map((s) => ({
  code: s.code, package_slug: s.courseSlug, title: s.title, start_date: s.startDate, end_date: s.endDate,
  start_time: s.time.split(" - ")[0].replace(".", ":"), end_time: s.time.split(" - ")[1].replace(" WIB", "").replace(".", ":"),
  city: s.city, location: s.location, mode: s.mode, quota: s.quota, registered_count: s.quota - s.remaining, price: s.price,
  // publishedAt dummy frontend = tanggal batch (masa depan) → geser 90 hari agar sudah terbit, urutan relatif tetap
  trainer_name: s.trainer, status: statusMap[s.status], published_at: iso(s.publishedAt - 90 * 86400),
}));
writeFileSync(resolve(out, "schedules.json"), JSON.stringify(sched, null, 2));

// ---- Partner & client + partner resmi (hero)
const official = home.HERO.officialPartners.map((p, i) => ({ name: p.name, slug: slugify(p.name), logo: p.logo, width: p.w, height: p.h, type: "principal", is_official: true, sort_order: i + 1 }));
const clients = home.PARTNERS.map((p, i) => ({ name: p.name, slug: slugify(p.name), logo: p.logo, width: p.w, height: p.h, type: "client", is_official: false, sort_order: 10 + i }));
writeFileSync(resolve(out, "partners.json"), JSON.stringify([...official, ...clients.filter((c) => !official.some((o) => o.slug === c.slug))], null, 2));

// ---- Testimoni ("role" = "Jabatan • Perusahaan • Kota")
const testi = testimonials.TESTIMONIALS.map((t, i) => {
  const [position, company, city] = t.role.split("•").map((x) => x.trim());
  return { name: t.name, position: position ?? null, company: company ?? null, city: city ?? null, photo: t.image ?? null, program: t.program ?? null, content: t.quote, rating: t.stars, is_approved: true, is_featured: i === 0, sort_order: i + 1 };
});
writeFileSync(resolve(out, "testimonials.json"), JSON.stringify(testi, null, 2));

// ---- Galeri beranda (hotlink pihak ketiga — ganti dengan foto PPTI)
writeFileSync(resolve(out, "galleries.json"), JSON.stringify(home.GALLERY.map((g, i) => ({ title: g.alt, image: g.src, caption: null, sort_order: i + 1 })), null, 2));

// ---- Postingan
const typeMap = { Tutorial: "tutorial", Berita: "news", Kegiatan: "activity", Dokumentasi: "documentation" };
const posts = articles.ARTICLES.map((a) => ({
  type: typeMap[a.category], slug: a.slug, title: a.title, excerpt: a.excerpt, body: blocksToHtml(a.body), thumbnail: a.image ?? null,
  tags: a.tags ?? [], author_name: a.author ?? null, status: "published", published_at: iso(a.publishedAt),
}));
writeFileSync(resolve(out, "posts.json"), JSON.stringify(posts, null, 2));

// ---- Halaman statis
writeFileSync(resolve(out, "pages.json"), JSON.stringify(pages.STATIC_PAGES.map((p) => ({ slug: p.slug, title: p.title, seo_description: p.description, body: blocksToHtml(p.body), status: "published" })), null, 2));

// ---- Menu (header + 3 kolom footer)
const menus = [];
site.NAV.forEach((n, i) => menus.push({ location: "header", label: n.label, url: n.href, is_external: !!n.external, sort_order: i + 1 }));
site.FOOTER_COLUMNS.forEach((col, ci) => col.links.forEach((l, i) => menus.push({ location: `footer_${ci + 1}`, group_title: col.title, label: l.label, url: l.href === "wa" ? "wa" : l.href, is_external: !!l.external || l.href === "wa", sort_order: i + 1 })));
writeFileSync(resolve(out, "menus.json"), JSON.stringify(menus, null, 2));

// ---- Banner hero (hotlink pihak ketiga — ganti dengan foto PPTI)
writeFileSync(resolve(out, "banners.json"), JSON.stringify(home.HERO_SLIDES.map((src, i) => ({ position: "hero", image: src, alt: "Training PPTI", sort_order: i + 1 })), null, 2));

// ---- Pengaturan situs (key → value)
const { officialPartners, ...hero } = home.HERO;
const settings = {
  site: { ...site.SITE, socials: site.SOCIALS },
  hero,
  stats: home.STATS,
  about: home.ABOUT,
  why: home.WHY,
  cta: home.CTA,
  company: { vision: about.VISION, missions: about.MISSIONS, facilities: about.FACILITIES, trainers: about.TRAINERS, services: about.SERVICES, partnership_points: about.PARTNERSHIP_POINTS },
  post_categories: articles.POST_CATEGORIES,
};
writeFileSync(resolve(out, "settings.json"), JSON.stringify(settings, null, 2));

console.log("seed data ditulis ke", out);
