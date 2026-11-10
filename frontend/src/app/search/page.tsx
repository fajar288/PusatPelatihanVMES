import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import ArticleCard from "@/components/cards/ArticleCard";
import CourseCard from "@/components/cards/CourseCard";
import { scheduleBatchTitle, shortDate } from "@/data/schedules";
import { SITE } from "@/data/site";
import { search } from "@/lib/cms";

export const metadata: Metadata = { title: `Pencarian | ${SITE.name}`, robots: { index: false } };

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

function SearchBox({ q }: { q: string }) {
  return (
    <form className="catb" action="/search" method="get" role="search" style={{ margin: "36px 0" }}>
      <div className="cats">
        <svg className="ic" viewBox="0 0 24 24">
          <circle cx="11" cy="11" r="8" />
          <path d="m21 21-4.3-4.3" />
        </svg>
        <input aria-label="Cari" name="q" defaultValue={q} placeholder="Cari kursus, jadwal, atau artikel..." type="search" />
      </div>
      <div className="catf">
        <button className="kb1" type="submit" style={{ padding: "0 26px", height: 48 }}>
          Cari
        </button>
      </div>
    </form>
  );
}

/** Hasil dari GET /api/v1/search?q= (fallback: pencarian lokal atas data statis). */
async function Results({ searchParams }: Pick<PageProps<"/search">, "searchParams">) {
  const q = first((await searchParams).q).trim();
  const r = q ? await search(q) : { q: "", total: 0, courses: [], schedules: [], posts: [] };
  const { courses, schedules, posts, total } = r;

  return (
    <>
      <section className="ph0">
        <div className="wrap">
          <h1>{q ? `Hasil pencarian “${q}”` : "Pencarian"}</h1>
          <p>{q ? `${total} hasil ditemukan` : "Masukkan kata kunci untuk mencari kursus, jadwal, dan artikel."}</p>
        </div>
      </section>
      <div className="wrap">
        <SearchBox q={q} />
        {q && total === 0 && (
          <div className="nores">
            <h2>Tidak ada hasil untuk “{q}”</h2>
            <p>Coba kata kunci lain, atau jelajahi katalog training kami.</p>
            <Link className="btn" href="/kursus">
              Lihat Katalog Training
            </Link>
          </div>
        )}
        {courses.length > 0 && (
          <>
            <div className="srh">
              <h2>Kursus</h2>
              <span>{courses.length} hasil</span>
            </div>
            <div className="list">
              {courses.map((c) => (
                <CourseCard key={c.slug} course={c} />
              ))}
            </div>
          </>
        )}
        {schedules.length > 0 && (
          <div className="g-jadwal">
            <div className="srh">
              <h2>Jadwal</h2>
              <span>{schedules.length} hasil</span>
            </div>
            <div className="list">
              {schedules.map((s) => (
                <Link className="card" key={s.id} href={`/jadwal/${s.id}`}>
                  <div className="noimg" />
                  <div className="cb">
                    <span className="tag">Jadwal</span>
                    <h3>{scheduleBatchTitle(s)}</h3>
                    <p>{s.city}</p>
                    <time dateTime={s.startDate}>{shortDate(s.startDate)}</time>
                    <span className="go">Lihat detail</span>
                  </div>
                </Link>
              ))}
            </div>
          </div>
        )}
        {posts.length > 0 && (
          <>
            <div className="srh">
              <h2>Artikel &amp; Berita</h2>
              <span>{posts.length} hasil</span>
            </div>
            <div className="list">
              {posts.map((a) => (
                <ArticleCard key={a.slug} article={a} />
              ))}
            </div>
          </>
        )}
        <div style={{ height: 60 }} />
      </div>
    </>
  );
}

/** /search?q= — hasil pencarian kursus, jadwal, dan postingan (form .sf di header mengirim ke sini). */
export default function SearchPage({ searchParams }: PageProps<"/search">) {
  return (
    <Suspense
      fallback={
        <section className="ph0">
          <div className="wrap">
            <h1>Pencarian</h1>
            <p>Mencari…</p>
          </div>
        </section>
      }
    >
      <Results searchParams={searchParams} />
    </Suspense>
  );
}
