"use client";

import CourseCard from "@/components/cards/CourseCard";
import type { CatalogMonth, Package } from "@/lib/cms-types";
import { type CatalogItem, useCatalog } from "@/lib/useCatalog";
import CatalogBar, { type CatalogFilter, type SortKey } from "./CatalogBar";

const SORT_OPTIONS: { value: SortKey; label: string }[] = [
  { value: "new", label: "Urutkan: Terbaru" },
  { value: "pop", label: "Urutkan: Populer" },
  { value: "lo", label: "Urutkan: Harga Terendah" },
  { value: "hi", label: "Urutkan: Harga Tertinggi" },
  { value: "az", label: "Urutkan: Nama A-Z" },
];

/** Atribut kartu - sama dengan data-* di tema asli: data-q = "{judul} kursus {vendor} {kategori} {deskripsi}  {mode} ". */
const getItem = (c: Package): CatalogItem => ({
  q: `${c.title} kursus ${c.vendor} ${c.category} ${c.desc}  ${c.mode} `.toLowerCase(),
  cat: c.category, mode: c.mode, pr: c.price, pop: !!c.popular, t: c.title, d: c.publishedAt,
  lvl: c.level, months: c.scheduleMonths,
});

type Props = {
  courses: Package[];
  categories: string[];
  modes: string[];
  /** Filter tambahan PRD (katalog: level & jadwal); kosong → select tidak tampil. */
  levels?: string[];
  months?: CatalogMonth[];
  /** Nilai awal dari query string (?q=, ?kategori=, ?mode=, ?level=, ?bulan=, ?urut=). */
  initial?: Partial<CatalogFilter>;
};

/** div.catb + div.list: katalog kursus dengan cari/filter/urut di sisi klien. */
export default function CourseCatalog({ courses, categories, modes, levels = [], months = [], initial }: Props) {
  const { filter, setFilter, visible } = useCatalog(courses, getItem, initial, {
    categories,
    modes,
    levels,
    months: months.map((m) => m.value),
    sorts: SORT_OPTIONS.map((o) => o.value),
  });
  return (
    <>
      <CatalogBar
        value={filter}
        onChange={setFilter}
        categories={categories}
        modes={modes}
        levels={levels}
        months={months}
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
