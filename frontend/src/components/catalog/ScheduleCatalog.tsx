"use client";

import Link from "next/link";
import { MetaList } from "@/components/portal/ui";
import { type Schedule, scheduleBatchTitle, shortDate } from "@/data/schedules";
import { type CatalogItem, useCatalog } from "@/lib/useCatalog";
import CatalogBar, { type CatalogFilter, type SortKey } from "./CatalogBar";
import ScheduleCalendar from "./ScheduleCalendar";

const SORT_OPTIONS: { value: SortKey; label: string }[] = [
  { value: "new", label: "Urutkan: Terbaru" },
  { value: "az", label: "Urutkan: Nama A-Z" },
];

const getItem = (s: Schedule): CatalogItem => ({
  q: `${scheduleBatchTitle(s)} jadwal ${s.category} ${s.city} ${s.mode} ${s.statusLabel}`.toLowerCase(),
  cat: s.category, mode: "", pr: s.price, pop: false, t: scheduleBatchTitle(s), d: s.publishedAt,
});

type Props = { schedules: Schedule[]; categories: string[]; initial?: Partial<CatalogFilter> };

/**
 * Halaman /jadwal (g-jadwal): bar cari/kategori/urut + list baris `a.card` (border-left lime, tanggal pill,
 * tombol oranye "Lihat detail") persis website lama, ditambah kalender bulanan (PRD §5.4).
 */
export default function ScheduleCatalog({ schedules, categories, initial }: Props) {
  const { filter, setFilter, visible } = useCatalog(schedules, getItem, initial, { categories, modes: [], sorts: SORT_OPTIONS.map((o) => o.value) });
  return (
    <>
      <CatalogBar value={filter} onChange={setFilter} categories={categories} sortOptions={SORT_OPTIONS} count={visible.length} label="postingan" />
      <div className="list" id="cat-list" data-label="postingan">
        {visible.map((s) => (
          <Link className="card" key={s.id} href={`/jadwal/${s.id}`}>
            <div className="noimg" />
            <div className="cb">
              <span className="tag">Jadwal</span>
              <h3>{scheduleBatchTitle(s)}</h3>
              <MetaList
                compact
                items={[
                  { icon: "pin", value: s.city },
                  { icon: "monitor", value: s.mode },
                  { icon: "clock", value: `${s.durationDays} Hari` },
                  { value: <span className={"jst " + s.status}>{s.statusLabel}</span> },
                ]}
              />
              <time dateTime={s.startDate}>{shortDate(s.startDate)}</time>
              <span className="go">Lihat detail</span>
            </div>
          </Link>
        ))}
        <p className="cem" hidden={visible.length > 0}>
          Tidak ada hasil yang cocok. Coba kata kunci atau filter lain.
        </p>
      </div>
      <ScheduleCalendar schedules={visible} />
    </>
  );
}
