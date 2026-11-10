import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import RelatedPosts from "@/components/detail/RelatedPosts";
import TopicCta from "@/components/detail/TopicCta";
import WhyBox from "@/components/detail/WhyBox";
import Icon from "@/components/ui/Icon";
import PostBody from "@/components/ui/PostBody";
import { ARTICLES, getCategorySlug, getPost, getRelatedPosts } from "@/data/articles";
import { SITE } from "@/data/site";
import { abs, JsonLd } from "@/lib/seo";

// Route detail: `params` dibaca langsung (bukan di dalam <Suspense>) supaya slug asing menghasilkan status 404
// yang benar. Konsekuensinya navigasi ke slug yang belum di-prerender tidak "instan" (cacheComponents) — dapat diterima
// karena slug yang dikenal sudah statis via generateStaticParams.
export const instant = false;

export function generateStaticParams() {
  return ARTICLES.map((a) => ({ slug: a.slug }));
}

export async function generateMetadata({ params }: PageProps<"/artikel/[slug]">): Promise<Metadata> {
  const { slug } = await params;
  const post = getPost(slug);
  if (!post) return { title: `Artikel tidak ditemukan | ${SITE.name}` };
  return {
    title: `${post.title} | ${SITE.name}`,
    description: post.excerpt,
    alternates: { canonical: `/artikel/${post.slug}` },
    openGraph: { title: post.title, description: post.excerpt, type: "article", locale: "id_ID", images: post.image ? [post.image] : undefined },
  };
}

/** /artikel/[slug] — clone single post website lama (g-artikel .kd): back link, chips, h1, tanggal, grid konten + sidebar sticky. */
export default async function ArtikelPage({ params }: PageProps<"/artikel/[slug]">) {
  const { slug } = await params;
  const post = getPost(slug);
  if (!post) notFound();
  const catSlug = getCategorySlug(post.category);
  const related = getRelatedPosts(post);

  const articleLd = {
    "@context": "https://schema.org",
    "@type": "Article",
    headline: post.title,
    description: post.excerpt,
    datePublished: new Date(post.publishedAt * 1000).toISOString(),
    image: post.image ? [post.image] : undefined,
    author: { "@type": "Organization", name: post.author ?? SITE.company },
    publisher: { "@type": "Organization", name: SITE.company, logo: { "@type": "ImageObject", url: abs(SITE.logo) } },
    mainEntityOfPage: abs(`/artikel/${post.slug}`),
  };

  return (
    <div className="g-artikel">
      <JsonLd data={articleLd} />
      <section className="kd" id="kd">
        <div className="wrap">
          <Link className="bk" href={`/${catSlug}`} id="kd-back">
            <Icon name="arrl" />
            Kembali ke {post.category}
          </Link>
          <div className="chips" id="kd-chips">
            <Link className="chp a" href={`/${catSlug}`}>
              {post.category}
            </Link>
            {post.tags?.map((t) => (
              <span className="chp" key={t}>
                {t}
              </span>
            ))}
          </div>
          <h1>{post.title}</h1>
          <div className="dmeta">
            {post.dateLabel}
            {post.author ? ` • ${post.author}` : ""}
          </div>
          <p className="rs" id="kd-rs"></p>
          <div className="kdg">
            <div className="kdl">
              <section className="kbx">
                <h2 id="kd-oh">Detail</h2>
                <div className="kov" id="kd-ov">
                  <PostBody blocks={post.body} />
                </div>
              </section>
              <RelatedPosts posts={related} variant="rt-d" />
            </div>
            <aside className="kdr">
              <div className="ksd" id="kd-side">
                <TopicCta topic={post.title} />
                <WhyBox />
                <RelatedPosts posts={related} variant="rt-m" />
              </div>
            </aside>
          </div>
        </div>
      </section>
    </div>
  );
}
