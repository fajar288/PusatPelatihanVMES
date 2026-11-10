import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import CatalogHero from "@/components/catalog/CatalogHero";
import type { SortKey } from "@/components/catalog/CatalogBar";
import PostCatalog from "@/components/catalog/PostCatalog";
import PostBody from "@/components/ui/PostBody";
import { getCategoryBySlug, getPostsByCategory, POST_CATEGORIES } from "@/data/articles";
import { getStaticPage, STATIC_PAGES } from "@/data/pages";
import { SITE } from "@/data/site";

/**
 * Route level-1 untuk dua jenis halaman website lama:
 *  - kategori postingan: /tutorial, /berita, /kegiatan, /dokumentasi (body g-artikel: hero + bar + list kartu horizontal)
 *  - halaman statis: /kebijakan-privasi, /syarat-ketentuan, /faq, /cara-pendaftaran (.ph0 + .single .in .pb)
 */
// Route detail: `params` dibaca langsung (bukan di dalam <Suspense>) supaya slug asing menghasilkan status 404
// yang benar. Konsekuensinya navigasi ke slug yang belum di-prerender tidak "instan" (cacheComponents) — dapat diterima
// karena slug yang dikenal sudah statis via generateStaticParams.
export const instant = false;

export function generateStaticParams() {
  return [...POST_CATEGORIES.map((c) => ({ slug: c.slug })), ...STATIC_PAGES.map((p) => ({ slug: p.slug }))];
}

export async function generateMetadata({ params }: PageProps<"/[slug]">): Promise<Metadata> {
  const { slug } = await params;
  const cat = getCategoryBySlug(slug);
  if (cat) return { title: `${cat.title} | ${SITE.name}`, description: cat.desc, alternates: { canonical: `/${cat.slug}` } };
  const page = getStaticPage(slug);
  if (page) return { title: `${page.title} | ${SITE.name}`, description: page.description, alternates: { canonical: `/${page.slug}` } };
  return { title: `Halaman tidak ditemukan | ${SITE.name}` };
}

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

async function CategoryFromParams({ category, searchParams }: { category: string; searchParams: PageProps<"/[slug]">["searchParams"] }) {
  const sp = await searchParams;
  const cat = getCategoryBySlug(category)!;
  return <PostCatalog posts={getPostsByCategory(cat.category)} categories={[cat.category]} initial={{ q: first(sp.q), sort: first(sp.urut) as SortKey }} />;
}

export default async function SlugPage({ params, searchParams }: PageProps<"/[slug]">) {
  const { slug } = await params;

  const cat = getCategoryBySlug(slug);
  if (cat) {
    const posts = getPostsByCategory(cat.category);
    return (
      <div className="g-artikel">
        <CatalogHero eyebrow="Artikel & Berita" title={cat.title} desc={cat.desc} />
        <div className="wrap">
          <Suspense fallback={<PostCatalog posts={posts} categories={[cat.category]} />}>
            <CategoryFromParams category={slug} searchParams={searchParams} />
          </Suspense>
          <div style={{ height: 60 }} />
        </div>
      </div>
    );
  }

  const page = getStaticPage(slug);
  if (!page) notFound();
  return (
    <>
      <section className="ph0">
        <div className="wrap">
          <h1>{page.title}</h1>
          {page.updatedLabel && <p>{page.updatedLabel}</p>}
        </div>
      </section>
      <div className="single">
        <div className="wrap">
          <div className="in">
            <PostBody blocks={page.body} />
          </div>
        </div>
      </div>
    </>
  );
}
