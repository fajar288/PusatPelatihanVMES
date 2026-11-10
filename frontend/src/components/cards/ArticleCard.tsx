import Link from "next/link";
import type { PostSummary } from "@/data/articles";

/** a.card — kartu artikel (grid beranda "Artikel terbaru" & list kategori). */
export default function ArticleCard({ article }: { article: PostSummary }) {
  return (
    <Link className="card" href={`/artikel/${article.slug}`}>
      {article.image ? <img alt={article.title} src={article.image} loading="lazy" referrerPolicy="no-referrer" /> : <div className="noimg" />}
      <div className="cb">
        <span className="tag">{article.category}</span>
        <h3>{article.title}</h3>
        <p>{article.excerpt}</p>
        <time>{article.dateLabel}</time>
        <span className="go">Lihat detail</span>
      </div>
    </Link>
  );
}
