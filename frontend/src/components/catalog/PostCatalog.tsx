"use client";

import ArticleCard from "@/components/cards/ArticleCard";
import type { Article } from "@/data/articles";
import { type CatalogItem, useCatalog } from "@/lib/useCatalog";
import CatalogBar, { type CatalogFilter, type SortKey } from "./CatalogBar";

// Halaman kategori artikel di website lama hanya punya 2 urutan (data-kur="0").
const SORT_OPTIONS: { value: SortKey; label: string }[] = [
  { value: "new", label: "Urutkan: Terbaru" },
  { value: "az", label: "Urutkan: Nama A-Z" },
];

/** data-q = "{judul} {kategori} {ringkasan}" (lowercase), sama dengan tema asli. */
const getItem = (a: Article): CatalogItem => ({
  q: `${a.title} ${a.category} ${a.excerpt}`.toLowerCase(),
  cat: a.category, mode: "", pr: 0, pop: false, t: a.title, d: a.publishedAt,
});

type Props = { posts: Article[]; categories: string[]; initial?: Partial<CatalogFilter> };

/** div.catb + div.list (g-artikel) — daftar postingan satu kategori dengan cari/urut. */
export default function PostCatalog({ posts, categories, initial }: Props) {
  const { filter, setFilter, visible } = useCatalog(posts, getItem, initial, { categories, modes: [], sorts: SORT_OPTIONS.map((o) => o.value) });
  return (
    <>
      <CatalogBar value={filter} onChange={setFilter} categories={categories} sortOptions={SORT_OPTIONS} count={visible.length} label="postingan" />
      <div className="list" id="cat-list" data-label="postingan">
        {visible.map((a) => (
          <ArticleCard key={a.slug} article={a} />
        ))}
        <p className="cem" hidden={visible.length > 0}>
          Tidak ada hasil yang cocok. Coba kata kunci atau filter lain.
        </p>
      </div>
    </>
  );
}
