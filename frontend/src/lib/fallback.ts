// Fallback data statis (src/data/*) dalam bentuk yang sama dengan respons API.
// Dipakai saat API Laravel tidak terjangkau (build CI tanpa backend, backend mati) agar situs tetap tampil.
import { FACILITIES, MISSIONS, PARTNERSHIP_POINTS, SERVICES, TRAINERS, VISION } from "@/data/about";
import { ARTICLES, getCategoryBySlug, getCategorySlug, getPost, getPostsByCategory, getRelatedPosts, LATEST_ARTICLES, type Article } from "@/data/articles";
import { COURSE_CATEGORIES, COURSE_MODES, COURSES, getCourse, getRelatedCourses } from "@/data/courses";
import { ABOUT, CATEGORIES, CTA, GALLERY, HERO, HERO_SLIDES, PARTNERS, STATS, WHY } from "@/data/home";
import { STATIC_PAGES } from "@/data/pages";
import { getUpcomingSchedules, SCHEDULES, scheduleBatchTitle, type Schedule } from "@/data/schedules";
import { FOOTER_COLUMNS, NAV, SITE, SOCIALS } from "@/data/site";
import { TESTIMONIALS } from "@/data/testimonials";
import { blocksToHtml } from "./blocks";
import type { CmsPage, CompanyProfile, HomeData, MenuData, Package, Post, ScheduleDetail, SearchResult, SiteSettings } from "./cms-types";

/** API memakai kode kelas sebagai id publik; data statis memakai "sch-n" → samakan. */
const sched = (s: Schedule): Schedule => ({ ...s, id: s.code });
const summary = (a: Article) => ({ slug: a.slug, title: a.title, excerpt: a.excerpt, category: a.category, dateLabel: a.dateLabel, publishedAt: a.publishedAt, image: a.image });

export const fallback = {
  site: (): SiteSettings => ({ ...SITE, socials: SOCIALS }),

  home: (): HomeData => {
    const { officialPartners, ...hero } = HERO;
    return {
      site: fallback.site(),
      hero: { ...hero, slides: HERO_SLIDES.map((src) => ({ src, alt: "Training PPTI" })), officialPartners },
      stats: STATS,
      about: ABOUT,
      courses: COURSES,
      categories: CATEGORIES,
      schedules: getUpcomingSchedules().slice(0, 8).map(sched),
      partners: PARTNERS,
      why: WHY,
      testimonials: TESTIMONIALS,
      gallery: GALLERY,
      articles: LATEST_ARTICLES.map(summary),
      cta: CTA,
    };
  },

  packages: () => ({ items: COURSES as Package[], categories: COURSE_CATEGORIES, modes: COURSE_MODES }),

  package: (slug: string): Package | null => {
    const c = getCourse(slug);
    if (!c) return null;
    return { ...c, schedules: getUpcomingSchedules().filter((s) => s.courseSlug === slug).map(sched), related: getRelatedCourses(c) };
  },

  schedules: (): Schedule[] => getUpcomingSchedules().map(sched),

  schedule: (code: string): ScheduleDetail | null => {
    const s = SCHEDULES.find((x) => x.code === code || x.id === code);
    if (!s) return null;
    const c = getCourse(s.courseSlug);
    const others = getUpcomingSchedules().filter((x) => x.id !== s.id);
    return {
      schedule: sched(s),
      package: c ? { slug: c.slug, title: c.title, desc: c.desc, overview: { intro: c.overview.intro, learn: c.overview.learn, requirements: c.overview.requirements } } : null,
      related: [...others.filter((x) => x.courseSlug === s.courseSlug), ...others.filter((x) => x.courseSlug !== s.courseSlug)].slice(0, 3).map(sched),
    };
  },

  posts: (typeSlug: string) => {
    const cat = getCategoryBySlug(typeSlug);
    return cat ? getPostsByCategory(cat.category).map(summary) : [];
  },

  post: (slug: string): Post | null => {
    const p = getPost(slug);
    if (!p) return null;
    return { ...summary(p), body: blocksToHtml(p.body), categorySlug: getCategorySlug(p.category), author: p.author ?? null, tags: p.tags ?? [], related: getRelatedPosts(p).map(summary) };
  },

  page: (slug: string): CmsPage | null => {
    const p = STATIC_PAGES.find((x) => x.slug === slug);
    return p ? { slug: p.slug, title: p.title, description: p.description, updatedLabel: p.updatedLabel ?? null, body: blocksToHtml(p.body) } : null;
  },

  menus: (): MenuData => ({ header: NAV, footer: FOOTER_COLUMNS }),

  company: (): CompanyProfile => ({ vision: VISION, missions: MISSIONS, facilities: FACILITIES, trainers: TRAINERS, services: SERVICES, partnership_points: PARTNERSHIP_POINTS }),

  search: (q: string): SearchResult => {
    const t = q.trim().toLowerCase();
    if (!t) return { q: "", total: 0, courses: [], schedules: [], posts: [] };
    const norm = (s: string) => s.toLowerCase();
    const courses = COURSES.filter((c) => norm(`${c.title} ${c.vendor} ${c.category} ${c.desc} ${c.mode}`).includes(t));
    const schedules = getUpcomingSchedules().filter((s) => norm(`${scheduleBatchTitle(s)} ${s.category} ${s.city} ${s.mode} ${s.code}`).includes(t)).map(sched);
    const posts = ARTICLES.filter((a) => norm(`${a.title} ${a.category} ${a.excerpt} ${(a.tags ?? []).join(" ")} ${blocksToHtml(a.body)}`).includes(t)).map(summary);
    return { q, total: courses.length + schedules.length + posts.length, courses, schedules, posts };
  },
};
