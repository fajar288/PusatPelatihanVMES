import type { Metadata } from "next";
import { Suspense } from "react";
import CatalogHero from "@/components/catalog/CatalogHero";
import type { SortKey } from "@/components/catalog/CatalogBar";
import ScheduleCatalog from "@/components/catalog/ScheduleCatalog";
import { SITE } from "@/data/site";
import { getSchedules } from "@/lib/cms";

export const metadata: Metadata = {
  title: `Jadwal Training | ${SITE.name}`,
  description: "Jadwal pelaksanaan training terbaru PPTI - VMES Training Center.",
  alternates: { canonical: "/jadwal" },
};

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";
const categoriesOf = (list: { category: string }[]) => [...new Set(list.map((s) => s.category).filter(Boolean))].sort();

async function FromParams({ searchParams }: Pick<PageProps<"/jadwal">, "searchParams">) {
  const [sp, schedules] = await Promise.all([searchParams, getSchedules()]);
  return <ScheduleCatalog schedules={schedules} categories={categoriesOf(schedules)} initial={{ q: first(sp.q), category: first(sp.kategori), sort: first(sp.urut) as SortKey }} />;
}

async function Static() {
  const schedules = await getSchedules();
  return <ScheduleCatalog schedules={schedules} categories={categoriesOf(schedules)} />;
}

/** /jadwal — clone `/category/jadwal/` (g-jadwal): hero, bar cari/kategori/urut, list baris + kalender bulanan. Data: GET /api/v1/schedules. */
export default function JadwalPage({ searchParams }: PageProps<"/jadwal">) {
  return (
    <div className="g-jadwal">
      <CatalogHero eyebrow="Jadwal Training" title="Jadwal" desc="Jadwal pelaksanaan training terbaru PPTI - VMES Training Center." />
      <div className="wrap">
        <Suspense fallback={<Static />}>
          <FromParams searchParams={searchParams} />
        </Suspense>
        <div style={{ height: 60 }} />
      </div>
    </div>
  );
}
