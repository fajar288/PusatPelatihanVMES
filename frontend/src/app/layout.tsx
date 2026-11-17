import type { Metadata, Viewport } from "next";
import { Plus_Jakarta_Sans } from "next/font/google";
import ContactCards from "@/components/layout/ContactCards";
import Footer from "@/components/layout/Footer";
import Header from "@/components/layout/Header";
import WaFloat from "@/components/layout/WaFloat";
import ChatWidget from "@/components/chat/ChatWidget";
import PwaProvider from "@/components/pwa/PwaProvider";
import IconSprite from "@/components/ui/IconSprite";
import { SiteProvider } from "@/components/site/SiteProvider";
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

export const viewport: Viewport = { themeColor: "#004B23" };

/**
 * Metadata dari pengaturan situs CMS. Halaman cukup menulis judulnya sendiri (mis. "Kontak"); nama situs
 * ditambahkan lewat template sehingga ikut berubah bila diganti admin.
 */
export async function generateMetadata(): Promise<Metadata> {
  const site = await getSiteSettings();
  return {
    metadataBase: new URL(SITE_URL),
    title: { default: site.name, template: `%s | ${site.name}` },
    description: site.description,
    icons: { icon: "/favicon.jpg", apple: "/icons/apple-touch-icon.png" },
    // PWA: manifest dari app/manifest.ts; mode aplikasi iOS
    appleWebApp: { capable: true, title: "PPTI VMES", statusBarStyle: "default" },
    alternates: { canonical: "/" },
    openGraph: { title: site.name, description: site.description, type: "website", locale: "id_ID", siteName: site.name, images: [site.logo] },
    twitter: { card: "summary", title: site.name, description: site.description },
  };
}

/** Identitas situs & menu dari CMS (cache "use cache"; fallback src/data/site.ts). */
export default async function RootLayout({ children }: LayoutProps<"/">) {
  const [site, menus] = await Promise.all([getSiteSettings(), getMenus()]);
  return (
    <html lang="id" className={jakarta.variable}>
      {/* class "is-home" diperlukan oleh rule `.is-home #home{display:block}` di theme.css */}
      <body className="home is-home">
        <JsonLd data={organizationJsonLd(site)} />
        <IconSprite />
        <SiteProvider site={site}>
          <Header site={site} nav={menus.header} />
          <main id="main">{children}</main>
          <ContactCards site={site} />
          <Footer site={site} columns={menus.footer} />
          <WaFloat site={site} />
          <ChatWidget />
          <PwaProvider />
        </SiteProvider>
      </body>
    </html>
  );
}
