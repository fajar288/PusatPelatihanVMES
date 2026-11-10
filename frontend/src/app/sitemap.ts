import type { MetadataRoute } from "next";
import { POST_CATEGORIES } from "@/data/articles";
import { STATIC_PAGES } from "@/data/pages";
import { getPackages, getPosts, getSchedules } from "@/lib/cms";
import { abs } from "@/lib/seo";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [pk, schedules, postLists] = await Promise.all([getPackages(), getSchedules(), Promise.all(POST_CATEGORIES.map((c) => getPosts(c.slug)))]);
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
  const ts = (unix?: number | null) => (unix ? new Date(unix * 1000) : now);
  const courses = pk.items.map((c) => ({ url: abs(`/kursus/${c.slug}`), lastModified: ts(c.publishedAt), changeFrequency: "monthly" as const, priority: 0.8 }));
  const batches = schedules.map((s) => ({ url: abs(`/jadwal/${s.id}`), lastModified: ts(s.publishedAt), changeFrequency: "weekly" as const, priority: 0.7 }));
  const posts = postLists.flat().map((a) => ({ url: abs(`/artikel/${a.slug}`), lastModified: ts(a.publishedAt), changeFrequency: "monthly" as const, priority: 0.5 }));
  return [...fixed, ...courses, ...batches, ...posts];
}
