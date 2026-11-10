import type { Metadata } from "next";
import { Suspense } from "react";
import CatalogHero from "@/components/catalog/CatalogHero";
import type { SortKey } from "@/components/catalog/CatalogBar";
import ScheduleCatalog from "@/components/catalog/ScheduleCatalog";
import { SCHEDULES } from "@/data/schedules";
import { SITE } from "@/data/site";

export const metadata: Metadata = {
  title: `Jadwal Training | ${SITE.name}`,
  description: "Jadwal pelaksanaan training terbaru PPTI - VMES Training Center.",
  alternates: { canonical: "/jadwal" },
};

const CATEGORIES = [...new Set(SCHEDULES.map((s) => s.category))].sort();
const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

async function FromParams({ searchParams }: Pick<PageProps<"/jadwal">, "searchParams">) {
  const sp = await searchParams;
  return <ScheduleCatalog schedules={SCHEDULES} categories={CATEGORIES} initial={{ q: first(sp.q), category: first(sp.kategori), sort: first(sp.urut) as SortKey }} />;
}

/** /jadwal — clone `/category/jadwal/` (g-jadwal): hero, bar cari/kategori/urut, list baris + kalender bulanan. */
export default function JadwalPage({ searchParams }: PageProps<"/jadwal">) {
  return (
    <div className="g-jadwal">
      <CatalogHero eyebrow="Jadwal Training" title="Jadwal" desc="Jadwal pelaksanaan training terbaru PPTI - VMES Training Center." />
      <div className="wrap">
        <Suspense fallback={<ScheduleCatalog schedules={SCHEDULES} categories={CATEGORIES} />}>
          <FromParams searchParams={searchParams} />
        </Suspense>
        <div style={{ height: 60 }} />
      </div>
    </div>
  );
}
