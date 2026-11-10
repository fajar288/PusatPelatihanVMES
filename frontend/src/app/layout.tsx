import type { Metadata } from "next";
import { Plus_Jakarta_Sans } from "next/font/google";
import ContactCards from "@/components/layout/ContactCards";
import Footer from "@/components/layout/Footer";
import Header from "@/components/layout/Header";
import WaFloat from "@/components/layout/WaFloat";
import IconSprite from "@/components/ui/IconSprite";
import { SITE } from "@/data/site";
import { getMenus, getSiteSettings } from "@/lib/cms";
import { JsonLd, organizationJsonLd, SITE_URL } from "@/lib/seo";
import "./globals.css";

// Font tema: Plus Jakarta Sans 400/500/700/800 (sama dengan <link> Google Fonts website lama)
const jakarta = Plus_Jakarta_Sans({
  subsets: ["latin"],
  weight: ["400", "500", "700", "800"],
  variable: "--font-jakarta",
  display: "swap",
});

export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  title: SITE.name,
  description: SITE.description,
  icons: { icon: "/favicon.jpg", apple: "/favicon.jpg" },
  alternates: { canonical: "/" },
  openGraph: { title: SITE.name, description: SITE.description, type: "website", locale: "id_ID", siteName: SITE.name, images: [SITE.logo] },
  twitter: { card: "summary", title: SITE.name, description: SITE.description },
};

/** Identitas situs & menu dari CMS (cache "use cache"; fallback src/data/site.ts). */
export default async function RootLayout({ children }: LayoutProps<"/">) {
  const [site, menus] = await Promise.all([getSiteSettings(), getMenus()]);
  return (
    <html lang="id" className={jakarta.variable}>
      {/* class "is-home" diperlukan oleh rule `.is-home #home{display:block}` di theme.css */}
      <body className="home is-home">
        <JsonLd data={organizationJsonLd()} />
        <IconSprite />
        <Header site={site} nav={menus.header} />
        <main id="main">{children}</main>
        <ContactCards site={site} />
        <Footer site={site} columns={menus.footer} />
        <WaFloat />
      </body>
    </html>
  );
}
