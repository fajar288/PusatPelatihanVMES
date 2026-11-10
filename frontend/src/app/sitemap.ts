import type { MetadataRoute } from "next";
import { ARTICLES, POST_CATEGORIES } from "@/data/articles";
import { COURSES } from "@/data/courses";
import { STATIC_PAGES } from "@/data/pages";
import { SCHEDULES } from "@/data/schedules";
import { abs } from "@/lib/seo";

export default function sitemap(): MetadataRoute.Sitemap {
  const now = new Date();
  const fixed: MetadataRoute.Sitemap = [
    { url: abs("/"), lastModified: now, changeFrequency: "weekly", priority: 1 },
    { url: abs("/kursus"), lastModified: now, changeFrequency: "weekly", priority: 0.9 },
    { url: abs("/jadwal"), lastModified: now, changeFrequency: "weekly", priority: 0.9 },
    { url: abs("/tentang"), lastModified: now, changeFrequency: "monthly", priority: 0.6 },
    { url: abs("/kontak"), lastModified: now, changeFrequency: "monthly", priority: 0.6 },
    ...POST_CATEGORIES.map((c) => ({ url: abs(`/${c.slug}`), lastModified: now, changeFrequency: "weekly" as const, priority: 0.7 })),
    ...STATIC_PAGES.map((p) => ({ url: abs(`/${p.slug}`), lastModified: now, changeFrequency: "yearly" as const, priority: 0.3 })),
  ];
  const courses = COURSES.map((c) => ({ url: abs(`/kursus/${c.slug}`), lastModified: new Date(c.publishedAt * 1000), changeFrequency: "monthly" as const, priority: 0.8 }));
  const schedules = SCHEDULES.map((s) => ({ url: abs(`/jadwal/${s.id}`), lastModified: new Date(s.publishedAt * 1000), changeFrequency: "weekly" as const, priority: 0.7 }));
  const posts = ARTICLES.map((a) => ({ url: abs(`/artikel/${a.slug}`), lastModified: new Date(a.publishedAt * 1000), changeFrequency: "monthly" as const, priority: 0.5 }));
  return [...fixed, ...courses, ...schedules, ...posts];
}
