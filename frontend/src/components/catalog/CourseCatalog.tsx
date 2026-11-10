"use client";

import { useMemo, useState } from "react";
import CourseCard from "@/components/cards/CourseCard";
import type { Course } from "@/data/courses";
import CatalogBar, { type CatalogFilter, type SortKey } from "./CatalogBar";

const SORT_OPTIONS: { value: SortKey; label: string }[] = [
  { value: "new", label: "Urutkan: Terbaru" },
  { value: "pop", label: "Urutkan: Populer" },
  { value: "lo", label: "Urutkan: Harga Terendah" },
  { value: "hi", label: "Urutkan: Harga Tertinggi" },
  { value: "az", label: "Urutkan: Nama A-Z" },
];

const SORT_KEYS: SortKey[] = SORT_OPTIONS.map((o) => o.value);

/** Teks pencarian per kartu — sama dengan atribut data-q di tema asli:
 *  "{judul} kursus {vendor} {kategori} {deskripsi}  {mode} " (lowercase). */
function searchText(c: Course): string {
  return `${c.title} kursus ${c.vendor} ${c.category} ${c.desc}  ${c.mode} `.toLowerCase();
}

/** Urutan identik dengan fungsi draw() di theme.main.js. */
function sortCourses(list: Course[], m: SortKey): Course[] {
  return [...list].sort((x, y) => {
    if (m === "az") return x.title.localeCompare(y.title);
    if (m === "lo") return (x.price || 1e12) - (y.price || 1e12);
    if (m === "hi") return y.price - x.price;
    if (m === "pop") return (Number(!!y.popular) - Number(!!x.popular)) || y.publishedAt - x.publishedAt;
    return y.publishedAt - x.publishedAt;
  });
}

type Props = {
  courses: Course[];
  categories: string[];
  modes: string[];
  /** Nilai awal dari query string (?q=, ?kategori=, ?mode=, ?urut=). */
  initial?: Partial<CatalogFilter>;
};

/** div.catb + div.list — katalog kursus dengan cari/filter/urut di sisi klien. */
export default function CourseCatalog({ courses, categories, modes, initial }: Props) {
  const [filter, setFilter] = useState<CatalogFilter>({
    q: initial?.q ?? "",
    category: categories.includes(initial?.category ?? "") ? (initial!.category as string) : "",
    mode: modes.includes(initial?.mode ?? "") ? (initial!.mode as string) : "",
    sort: SORT_KEYS.includes(initial?.sort as SortKey) ? (initial!.sort as SortKey) : "new",
  });

  const visible = useMemo(() => {
    const t = filter.q.trim().toLowerCase();
    const ok = courses.filter(
      (c) =>
        (!t || searchText(c).includes(t)) &&
        (!filter.category || c.category === filter.category) &&
        (!filter.mode || c.mode === filter.mode),
    );
    return sortCourses(ok, filter.sort);
  }, [courses, filter]);

  return (
    <>
      <CatalogBar
        value={filter}
        onChange={setFilter}
        categories={categories}
        modes={modes}
        sortOptions={SORT_OPTIONS}
        count={visible.length}
        label="training"
      />
      <div className="list" id="cat-list" data-label="training">
        {visible.map((c) => (
          <CourseCard key={c.slug} course={c} />
        ))}
        <p className="cem" hidden={visible.length > 0}>
          Tidak ada hasil yang cocok. Coba kata kunci atau filter lain.
        </p>
      </div>
    </>
  );
}
