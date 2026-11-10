import type { Metadata } from "next";
import { Plus_Jakarta_Sans } from "next/font/google";
import ContactCards from "@/components/layout/ContactCards";
import Footer from "@/components/layout/Footer";
import Header from "@/components/layout/Header";
import IconSprite from "@/components/ui/IconSprite";
import { SITE } from "@/data/site";
import "./globals.css";

// Font tema: Plus Jakarta Sans 400/500/700/800 (sama dengan <link> Google Fonts website lama)
const jakarta = Plus_Jakarta_Sans({
  subsets: ["latin"],
  weight: ["400", "500", "700", "800"],
  variable: "--font-jakarta",
  display: "swap",
});

export const metadata: Metadata = {
  title: SITE.name,
  description: SITE.description,
  icons: { icon: "/favicon.jpg", apple: "/favicon.jpg" },
  openGraph: { title: SITE.name, description: SITE.description, type: "website", locale: "id_ID" },
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="id" className={jakarta.variable}>
      {/* class "is-home" diperlukan oleh rule `.is-home #home{display:block}` di theme.css */}
      <body className="home is-home">
        <IconSprite />
        <Header />
        <main id="main">{children}</main>
        <ContactCards />
        <Footer />
      </body>
    </html>
  );
}
