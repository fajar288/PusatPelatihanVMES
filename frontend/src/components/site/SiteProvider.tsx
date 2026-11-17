"use client";

import { createContext, useContext } from "react";
import { SITE, SOCIALS } from "@/data/site";
import type { SiteSettings } from "@/lib/cms-types";

const SiteContext = createContext<SiteSettings | null>(null);

/**
 * Identitas & kontak situs dari pengaturan CMS (key `site`) untuk komponen klien.
 * Diisi sekali di app/layout.tsx dari getSiteSettings(); tanpa provider jatuh ke data statis (src/data/site.ts, fallback yang sama dengan lib/fallback.ts).
 */
export function SiteProvider({ site, children }: { site: SiteSettings; children: React.ReactNode }) {
  return <SiteContext.Provider value={site}>{children}</SiteContext.Provider>;
}

export function useSite(): SiteSettings {
  return useContext(SiteContext) ?? { ...SITE, socials: SOCIALS };
}
