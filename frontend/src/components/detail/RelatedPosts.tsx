import Link from "next/link";
import Icon from "@/components/ui/Icon";
import type { Article } from "@/data/articles";

type Props = { posts: Article[]; variant: "rt-d" | "rt-m"; title?: string };

/** div.rt > .rcg > a.rc — "Artikel Terkait" di halaman detail post. */
export default function RelatedPosts({ posts, variant, title = "Artikel Terkait" }: Props) {
  if (!posts.length) return null;
  return (
    <div className={"rt " + variant}>
      <h2>{title}</h2>
      <div className="rcg">
        {posts.map((p) => (
          <Link className="rc" key={p.slug} href={`/artikel/${p.slug}`}>
            <div className="rci">{p.image && <img alt={p.title} src={p.image} loading="lazy" referrerPolicy="no-referrer" />}</div>
            <div className="rcb">
              <h3>{p.title}</h3>
              <span>
                Baca selengkapnya <Icon name="arr" />
              </span>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}
