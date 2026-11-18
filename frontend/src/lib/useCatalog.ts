"use client";

import { useMemo, useState } from "react";
import type { CatalogFilter, SortKey } from "@/components/catalog/CatalogBar";

/** Atribut yang dibaca fungsi draw() di theme.main.js dari tiap kartu (data-q, data-cat, data-mode, data-pr, data-pop, data-t, data-d). */
export type CatalogItem = {
  q: string; // teks pencarian (lowercase)
  cat: string;
  mode: string;
  pr: number;
  pop: boolean;
  t: string;
  d: number;
  /** Tambahan katalog kursus (bukan dari tema asli): level & bulan mulai jadwal (YYYY-MM). */
  lvl?: string;
  months?: string[];
};

export function sortItems<T>(list: T[], get: (x: T) => CatalogItem, m: SortKey): T[] {
  return [...list].sort((a, b) => {
    const x = get(a), y = get(b);
    if (m === "az") return x.t.localeCompare(y.t);
    if (m === "lo") return (x.pr || 1e12) - (y.pr || 1e12);
    if (m === "hi") return y.pr - x.pr;
    if (m === "pop") return (Number(y.pop) - Number(x.pop)) || y.d - x.d;
    return y.d - x.d;
  });
}

/** State cari/filter/urut + daftar hasil, logika identik dengan katalog website lama. */
export function useCatalog<T>(
  items: T[],
  get: (x: T) => CatalogItem,
  initial: Partial<CatalogFilter> | undefined,
  allowed: { categories: string[]; modes: string[]; sorts: SortKey[]; levels?: string[]; months?: string[] },
) {
  const pick = (v: string | undefined, list: string[] | undefined) => (v && list?.includes(v) ? v : "");
  const [filter, setFilter] = useState<CatalogFilter>({
    q: initial?.q ?? "",
    category: pick(initial?.category, allowed.categories),
    mode: pick(initial?.mode, allowed.modes),
    level: pick(initial?.level, allowed.levels),
    month: pick(initial?.month, allowed.months),
    sort: allowed.sorts.includes(initial?.sort as SortKey) ? (initial!.sort as SortKey) : "new",
  });
  const visible = useMemo(() => {
    const t = filter.q.trim().toLowerCase();
    const ok = items.filter((it) => {
      const a = get(it);
      return (
        (!t || a.q.includes(t)) &&
        (!filter.category || a.cat === filter.category) &&
        (!filter.mode || a.mode === filter.mode) &&
        (!filter.level || a.lvl === filter.level) &&
        (!filter.month || (a.months ?? []).includes(filter.month))
      );
    });
    return sortItems(ok, get, filter.sort);
  }, [items, get, filter]);
  return { filter, setFilter, visible };
}
