import type { SiteSettings } from "@/lib/cms-types";

/** URL publik situs: set NEXT_PUBLIC_SITE_URL di produksi; default domain lama. */
export const SITE_URL = (process.env.NEXT_PUBLIC_SITE_URL ?? "https://pusatpelatihan.id").replace(/\/$/, "");

export const abs = (path: string) => `${SITE_URL}${path.startsWith("/") ? path : "/" + path}`;

/** JSON-LD organisasi dari pengaturan situs CMS (getSiteSettings()). */
export function organizationJsonLd(site: SiteSettings) {
  return {
    "@context": "https://schema.org",
    "@type": "EducationalOrganization",
    name: site.company,
    alternateName: site.name,
    url: SITE_URL,
    logo: abs(site.logo),
    description: site.description,
    telephone: "+" + site.waNumber,
    // Alamat lengkap satu baris dari pengaturan (kota & kode pos ikut di dalamnya, jadi tidak ditulis terpisah di kode)
    address: { "@type": "PostalAddress", streetAddress: site.address, addressCountry: "ID" },
  };
}

/** <script type="application/ld+json">: dipakai di layout & halaman detail. */
export function JsonLd({ data }: { data: object }) {
  return <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(data) }} />;
}
