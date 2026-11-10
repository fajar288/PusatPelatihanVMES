import Link from "next/link";
import type { Article } from "@/data/articles";

/** a.card — kartu artikel (grid beranda "Artikel terbaru" & list kategori). */
export default function ArticleCard({ article }: { article: Article }) {
  return (
    <Link className="card" href={`/artikel/${article.slug}`}>
      {article.image ? <img alt={article.title} src={article.image} loading="lazy" /> : <div className="noimg" />}
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
