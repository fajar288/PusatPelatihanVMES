import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import CatalogHero from "@/components/catalog/CatalogHero";
import type { SortKey } from "@/components/catalog/CatalogBar";
import PostCatalog from "@/components/catalog/PostCatalog";
import { getCategoryBySlug, POST_CATEGORIES } from "@/data/articles";
import { STATIC_PAGES } from "@/data/pages";
import { SITE } from "@/data/site";
import { getPage, getPosts } from "@/lib/cms";

/**
 * Route level-1 untuk dua jenis halaman website lama:
 *  - kategori postingan: /tutorial, /berita, /kegiatan, /dokumentasi (g-artikel) — GET /api/v1/posts?type=
 *  - halaman statis: /kebijakan-privasi, /syarat-ketentuan, /faq, /cara-pendaftaran — GET /api/v1/pages/{slug}
 */
export const instant = false;

export function generateStaticParams() {
  return [...POST_CATEGORIES.map((c) => ({ slug: c.slug })), ...STATIC_PAGES.map((p) => ({ slug: p.slug }))];
}

export async function generateMetadata({ params }: PageProps<"/[slug]">): Promise<Metadata> {
  const { slug } = await params;
  const cat = getCategoryBySlug(slug);
  if (cat) return { title: `${cat.title} | ${SITE.name}`, description: cat.desc, alternates: { canonical: `/${cat.slug}` } };
  const page = await getPage(slug);
  if (page) return { title: `${page.title} | ${SITE.name}`, description: page.description ?? undefined, alternates: { canonical: `/${page.slug}` } };
  return { title: `Halaman tidak ditemukan | ${SITE.name}` };
}

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

async function CategoryFromParams({ category, searchParams }: { category: string; searchParams: PageProps<"/[slug]">["searchParams"] }) {
  const cat = getCategoryBySlug(category)!;
  const [sp, posts] = await Promise.all([searchParams, getPosts(cat.slug)]);
  return <PostCatalog posts={posts} categories={[cat.category]} initial={{ q: first(sp.q), sort: first(sp.urut) as SortKey }} />;
}

async function CategoryStatic({ category }: { category: string }) {
  const cat = getCategoryBySlug(category)!;
  const posts = await getPosts(cat.slug);
  return <PostCatalog posts={posts} categories={[cat.category]} />;
}

export default async function SlugPage({ params, searchParams }: PageProps<"/[slug]">) {
  const { slug } = await params;

  const cat = getCategoryBySlug(slug);
  if (cat) {
    return (
      <div className="g-artikel">
        <CatalogHero eyebrow="Artikel & Berita" title={cat.title} desc={cat.desc} />
        <div className="wrap">
          <Suspense fallback={<CategoryStatic category={slug} />}>
            <CategoryFromParams category={slug} searchParams={searchParams} />
          </Suspense>
          <div style={{ height: 60 }} />
        </div>
      </div>
    );
  }

  const page = await getPage(slug);
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
            {/* HTML dari CMS (editor admin) — sama seperti WordPress lama merender konten ke .pb */}
            <div className="pb" dangerouslySetInnerHTML={{ __html: page.body }} />
          </div>
        </div>
      </div>
    </>
  );
}
