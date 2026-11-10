import Link from "next/link";
import ArticleCard from "@/components/cards/ArticleCard";
import type { PostSummary } from "@/data/articles";

/** section.sec "Artikel terbaru" — grid 3 kartu .card. */
export default function LatestArticles({ articles }: { articles: PostSummary[] }) {
  return (
    <section className="sec">
      <div className="wrap">
        <div className="sh">
          <h2>Artikel terbaru</h2>
          <Link href="/tutorial">Semua tutorial</Link>
        </div>
        <div className="grid" id="h-artikel">
          {articles.map((a) => (
            <ArticleCard key={a.slug} article={a} />
          ))}
        </div>
      </div>
    </section>
  );
}
