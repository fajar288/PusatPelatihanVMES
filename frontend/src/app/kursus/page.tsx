import type { Metadata } from "next";
import { Suspense } from "react";
import CatalogHero from "@/components/catalog/CatalogHero";
import CourseCatalog from "@/components/catalog/CourseCatalog";
import type { SortKey } from "@/components/catalog/CatalogBar";
import { COURSE_CATEGORIES, COURSE_MODES, COURSES } from "@/data/courses";
import { SITE } from "@/data/site";

export const metadata: Metadata = {
  title: `Katalog Training | ${SITE.name}`,
  description:
    "Temukan program pelatihan bersertifikasi untuk teknologi industri, IT, otomasi, dan pengembangan SDM digital di PPTI - VMES Training Center.",
  alternates: { canonical: "/kursus" },
};

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

/** Membaca query string (?q=, ?kategori=, ?mode=, ?urut=) — harus di dalam <Suspense> karena cacheComponents aktif. */
async function CatalogFromParams({ searchParams }: Pick<PageProps<"/kursus">, "searchParams">) {
  const sp = await searchParams;
  return (
    <CourseCatalog
      courses={COURSES}
      categories={COURSE_CATEGORIES}
      modes={COURSE_MODES}
      initial={{ q: first(sp.q), category: first(sp.kategori), mode: first(sp.mode), sort: first(sp.urut) as SortKey }}
    />
  );
}

/**
 * /kursus — katalog training (clone `/category/kursus/` website lama, body class g-kursus):
 * hero `.cath` → bar `.catb` (cari, kategori, mode, urutan) → grid `.list` kartu `.cc`.
 * Shell statis di-prerender; fallback Suspense = katalog tanpa filter (markup sama) agar tidak ada loncatan layout.
 */
export default function KursusPage({ searchParams }: PageProps<"/kursus">) {
  return (
    <div className="g-kursus">
      <CatalogHero
        eyebrow="Katalog Training"
        title={
          <>
            Pilih Training yang Tepat untuk <em>Naik Level Karir</em>
          </>
        }
        desc="Temukan program pelatihan bersertifikasi untuk teknologi industri, IT, otomasi, dan pengembangan SDM digital di PPTI - VMES Training Center."
      />
      <div className="wrap">
        <Suspense fallback={<CourseCatalog courses={COURSES} categories={COURSE_CATEGORIES} modes={COURSE_MODES} />}>
          <CatalogFromParams searchParams={searchParams} />
        </Suspense>
        <div style={{ height: 60 }} />
      </div>
    </div>
  );
}
