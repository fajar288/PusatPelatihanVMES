import type { MetadataRoute } from "next";
import { abs, SITE_URL } from "@/lib/seo";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: [{ userAgent: "*", allow: "/", disallow: ["/search", "/daftar", "/portal/", "/admin/", "/trainer/"] }],
    sitemap: abs("/sitemap.xml"),
    host: SITE_URL,
  };
}
