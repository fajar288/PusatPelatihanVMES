import { SITE } from "@/data/site";

/** URL publik situs — set NEXT_PUBLIC_SITE_URL di produksi; default domain lama. */
export const SITE_URL = (process.env.NEXT_PUBLIC_SITE_URL ?? "https://pusatpelatihan.id").replace(/\/$/, "");

export const abs = (path: string) => `${SITE_URL}${path.startsWith("/") ? path : "/" + path}`;

export function organizationJsonLd() {
  return {
    "@context": "https://schema.org",
    "@type": "EducationalOrganization",
    name: SITE.company,
    alternateName: SITE.name,
    url: SITE_URL,
    logo: abs(SITE.logo),
    description: SITE.description,
    telephone: "+" + SITE.waNumber,
    address: { "@type": "PostalAddress", streetAddress: SITE.address, addressLocality: "Batam", addressRegion: "Kepulauan Riau", postalCode: "29464", addressCountry: "ID" },
  };
}

/** <script type="application/ld+json"> — dipakai di layout & halaman detail. */
export function JsonLd({ data }: { data: object }) {
  return <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(data) }} />;
}
