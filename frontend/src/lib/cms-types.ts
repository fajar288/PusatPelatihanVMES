// Bentuk data yang dikonsumsi komponen — identik untuk sumber API Laravel maupun fallback statis (src/data).
import type { IconName } from "@/components/ui/icons";
import type { PostSummary } from "@/data/articles";
import type { Course } from "@/data/courses";
import type { Schedule } from "@/data/schedules";
import type { NavItem } from "@/data/site";
import type { Testimonial } from "@/data/testimonials";

export type SiteSettings = {
  name: string;
  shortName: string;
  company: string;
  description: string;
  logo: string;
  logoSize: { w: number; h: number };
  waNumber: string;
  waDisplay: string;
  waDefaultText: string;
  address: string;
  mapsUrl: string;
  youtubeProfileId: string;
  footerDescription: string;
  tagline: string;
  socials: { icon: "yt" | "fb" | "tw" | "ig" | "in"; href: string; label: string }[];
};

export type PartnerLogo = { name: string; logo: string; w?: number | null; h?: number | null };

export type HeroData = {
  pill: string;
  titleLine1: string;
  titleLine2: string;
  lead: string;
  features: { icon: IconName; label: string }[];
  glassStat: { icon: IconName; title: string; sub: string };
  slides: { src: string; alt?: string | null }[];
  officialPartners: PartnerLogo[];
};

export type StatItem = { icon: IconName; n: number; suffix: string; label: string };
export type AboutData = { eyebrow: string; titlePrefix: string; titleEm: string; paragraphs: string[]; tagline: string };
export type CategoryItem = { slug?: string; icon: IconName | null; title: string; desc: string | null; href: string };
export type WhyData = { eyebrow: string; title: string; sub: string; items: { icon: IconName; title: string; desc: string }[] };
export type CtaData = { eyebrow: string; title: string; desc: string; points: string[] };
export type GalleryItem = { src: string; alt: string };

export type Post = PostSummary & {
  body?: string; // HTML
  categorySlug?: string;
  author?: string | null;
  tags?: string[];
  related?: PostSummary[];
};

export type CmsPage = { slug: string; title: string; description?: string | null; updatedLabel?: string | null; body: string };

export type Package = Course & {
  categorySlug?: string | null;
  schedules?: Schedule[];
  related?: Course[];
  curricula?: { title: string; topics: string[]; durationHours?: number | null; method?: string | null; outcomes?: string | null }[];
};

export type ScheduleDetail = {
  schedule: Schedule;
  package: { slug: string; title: string; desc?: string | null; overview: { intro: string[]; learn: string[]; requirements: string[] } } | null;
  related: Schedule[];
};

export type MenuData = { header: NavItem[]; footer: { title: string; links: NavItem[] }[] };

export type CompanyProfile = {
  vision: string;
  missions: string[];
  facilities: { icon: IconName; title: string; desc: string }[];
  trainers: { name: string; role: string; certs: string; initials: string }[];
  services: { icon: IconName; title: string; desc: string; href: string }[];
  partnership_points: string[];
};

export type HomeData = {
  site: SiteSettings;
  hero: HeroData;
  stats: StatItem[];
  about: AboutData;
  courses: Course[];
  categories: CategoryItem[];
  schedules: Schedule[];
  partners: PartnerLogo[];
  why: WhyData;
  testimonials: Testimonial[];
  gallery: GalleryItem[];
  articles: PostSummary[];
  cta: CtaData;
};

export type SearchResult = { q: string; total: number; courses: Course[]; schedules: Schedule[]; posts: PostSummary[] };
