// Lapisan data CMS: fetch API Laravel (/api/v1) dengan cache Next 16 ("use cache"), fallback ke data statis.
// Semua halaman publik memanggil fungsi di sini — komponen tidak lagi mengimpor src/data secara langsung.
//
// Cache: cacheLife("hours") + cacheTag("cms", <bagian>). Backend memanggil POST /api/revalidate saat konten
// dipublikasikan (lihat app/api/revalidate/route.ts) sehingga halaman statis diperbarui tanpa build ulang.
import { cacheLife, cacheTag } from "next/cache";
import type { PostSummary } from "@/data/articles";
import type { Course } from "@/data/courses";
import type { Schedule } from "@/data/schedules";
import type { CmsPage, CompanyProfile, HomeData, MenuData, Package, Post, ScheduleDetail, SearchResult, SiteSettings } from "./cms-types";
import { fallback } from "./fallback";

const BASE = (process.env.API_URL_INTERNAL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");

type ApiResult<T> = { ok: true; data: T } | { ok: false; status: number };

/** GET ke API. status 0 = tidak terjangkau (→ fallback), 404 = tidak ada (→ notFound). */
async function api<T>(path: string): Promise<ApiResult<T>> {
  try {
    const res = await fetch(`${BASE}${path}`, { headers: { Accept: "application/json" }, signal: AbortSignal.timeout(8000) });
    if (!res.ok) {
      if (res.status !== 404) console.warn(`[cms] ${path} → HTTP ${res.status}; memakai data fallback`);
      return { ok: false, status: res.status === 404 ? 404 : 0 };
    }
    return { ok: true, data: (await res.json()) as T };
  } catch (e) {
    console.warn(`[cms] ${path} gagal (${(e as Error).message}); memakai data fallback`);
    return { ok: false, status: 0 };
  }
}

// ---- normalisasi kecil: API boleh null, komponen mengharapkan string ----
const course = (c: Course & { price: number | null }): Course => ({ ...c, price: c.price ?? 0, vendor: c.vendor ?? "", category: c.category ?? "", desc: c.desc ?? "" });
const schedule = (s: Schedule & { time: string | null; trainer: string | null; price: number | null }): Schedule => ({
  ...s, id: s.code ?? s.id, time: s.time ?? "", trainer: s.trainer ?? "", price: s.price ?? 0, category: s.category ?? "", city: s.city ?? "", location: s.location ?? "",
});

export async function getSiteSettings(): Promise<SiteSettings> {
  "use cache";
  cacheLife("hours");
  cacheTag("cms", "settings");
  const r = await api<{ value: SiteSettings | null }>("/settings?key=site");
  return r.ok && r.data.value ? { ...fallback.site(), ...r.data.value } : fallback.site();
}

export async function getMenus(): Promise<MenuData> {
  "use cache";
  cacheLife("hours");
  cacheTag("cms", "menus");
  const r = await api<MenuData>("/menus");
  return r.ok && r.data.header?.length ? r.data : fallback.menus();
}

export async function getHome(): Promise<HomeData> {
  "use cache";
  cacheLife("hours");
  cacheTag("cms", "home");
  const r = await api<{ data: HomeData }>("/home");
  if (!r.ok) return fallback.home();
  const d = r.data.data;
  const fb = fallback.home();
  return {
    ...fb,
    ...d,
    site: { ...fb.site, ...(d.site ?? {}) },
    hero: { ...fb.hero, ...(d.hero ?? {}), slides: d.hero?.slides?.length ? d.hero.slides : fb.hero.slides },
    courses: (d.courses ?? []).map(course),
    schedules: (d.schedules ?? []).map(schedule),
  };
}

export async function getPackages(): Promise<{ items: Package[]; categories: string[]; modes: string[] }> {
  "use cache";
  cacheLife("hours");
  cacheTag("cms", "packages");
  const r = await api<{ data: Package[]; meta: { categories: string[]; modes: string[] } }>("/packages");
  if (!r.ok) return fallback.packages();
  return { items: r.data.data.map(course), categories: r.data.meta?.categories ?? [], modes: r.data.meta?.modes ?? [] };
}

export async function getPackage(slug: string): Promise<Package | null> {
  "use cache";
  cacheLife("hours");
  cacheTag("cms", "packages", `package:${slug}`);
  const r = await api<{ data: Package }>(`/packages/${encodeURIComponent(slug)}`);
  if (r.ok) return { ...course(r.data.data), schedules: (r.data.data.schedules ?? []).map(schedule), related: (r.data.data.related ?? []).map(course) };
  return r.status === 404 ? null : fallback.package(slug);
}

export async function getSchedules(): Promise<Schedule[]> {
  "use cache";
  cacheLife("hours");
  cacheTag("cms", "schedules");
  const r = await api<{ data: Schedule[] }>("/schedules");
  return r.ok ? r.data.data.map(schedule) : fallback.schedules();
}

export async function getSchedule(code: string): Promise<ScheduleDetail | null> {
  "use cache";
  cacheLife("hours");
  cacheTag("cms", "schedules", `schedule:${code}`);
  const r = await api<{ data: Schedule; package: ScheduleDetail["package"]; related: Schedule[] }>(`/schedules/${encodeURIComponent(code)}`);
  if (r.ok) return { schedule: schedule(r.data.data), package: r.data.package ?? null, related: (r.data.related ?? []).map(schedule) };
  return r.status === 404 ? null : fallback.schedule(code);
}

export async function getPosts(typeSlug: string): Promise<PostSummary[]> {
  "use cache";
  cacheLife("hours");
  cacheTag("cms", "posts", `posts:${typeSlug}`);
  const r = await api<{ data: PostSummary[] }>(`/posts?type=${encodeURIComponent(typeSlug)}&per_page=100`);
  return r.ok ? r.data.data : fallback.posts(typeSlug);
}

export async function getPost(slug: string): Promise<Post | null> {
  "use cache";
  cacheLife("hours");
  cacheTag("cms", "posts", `post:${slug}`);
  const r = await api<{ data: Post }>(`/posts/${encodeURIComponent(slug)}`);
  if (r.ok) return r.data.data;
  return r.status === 404 ? null : fallback.post(slug);
}

export async function getPage(slug: string): Promise<CmsPage | null> {
  "use cache";
  cacheLife("hours");
  cacheTag("cms", "pages", `page:${slug}`);
  const r = await api<{ data: CmsPage }>(`/pages/${encodeURIComponent(slug)}`);
  if (r.ok) return r.data.data;
  return r.status === 404 ? null : fallback.page(slug);
}

export async function getCompanyProfile(): Promise<CompanyProfile> {
  "use cache";
  cacheLife("hours");
  cacheTag("cms", "settings");
  const r = await api<{ value: CompanyProfile | null }>("/settings?key=company");
  return r.ok && r.data.value ? { ...fallback.company(), ...r.data.value } : fallback.company();
}

/** Pencarian: tidak di-cache (bergantung query), dipanggil di dalam <Suspense>. */
export async function search(q: string): Promise<SearchResult> {
  const r = await api<{ data: SearchResult }>(`/search?q=${encodeURIComponent(q)}`);
  if (!r.ok) return fallback.search(q);
  const d = r.data.data;
  return { ...d, courses: (d.courses ?? []).map(course), schedules: (d.schedules ?? []).map(schedule) };
}
