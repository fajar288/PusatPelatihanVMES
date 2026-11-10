import About from "@/components/home/About";
import CategoriesSchedules from "@/components/home/CategoriesSchedules";
import CourseSlider from "@/components/home/CourseSlider";
import CtaBanner from "@/components/home/CtaBanner";
import Gallery from "@/components/home/Gallery";
import Hero from "@/components/home/Hero";
import LatestArticles from "@/components/home/LatestArticles";
import Partners from "@/components/home/Partners";
import Testimonials from "@/components/home/Testimonials";
import WhyUs from "@/components/home/WhyUs";
import { getHome } from "@/lib/cms";

/**
 * Beranda — urutan section identik dengan pusatpelatihan.id:
 * hero(+stats) → tentang → kursus → kategori+jadwal → partner → mengapa → testimoni → galeri → artikel → CTA
 * (kontak & footer ada di layout). Data dari GET /api/v1/home (cache "use cache", fallback src/data).
 */
export default async function HomePage() {
  const h = await getHome();
  return (
    <div id="home">
      {/* LCP: slide hero pertama di-preload prioritas tinggi (React 19 meng-hoist <link> ini ke <head>) */}
      {h.hero.slides[0] && <link rel="preload" as="image" href={h.hero.slides[0].src} fetchPriority="high" />}
      <Hero hero={h.hero} stats={h.stats} />
      <About about={h.about} youtubeId={h.site.youtubeProfileId} />
      <CourseSlider courses={h.courses} />
      <CategoriesSchedules categories={h.categories} schedules={h.schedules} />
      <Partners partners={h.partners} />
      <WhyUs why={h.why} />
      <Testimonials items={h.testimonials} />
      <Gallery items={h.gallery} />
      <LatestArticles articles={h.articles} />
      <CtaBanner cta={h.cta} />
    </div>
  );
}
