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
import { LATEST_ARTICLES } from "@/data/articles";
import { COURSES } from "@/data/courses";
import { SCHEDULES } from "@/data/schedules";
import { TESTIMONIALS } from "@/data/testimonials";

/**
 * Beranda — urutan section identik dengan pusatpelatihan.id:
 * hero(+stats) → tentang → kursus → kategori+jadwal → partner → mengapa → testimoni → galeri → artikel → CTA
 * (kontak & footer ada di layout). Data dummy dari src/data; nanti diganti fetch API Laravel.
 */
export default function HomePage() {
  return (
    <div id="home">
      <Hero />
      <About />
      <CourseSlider courses={COURSES} />
      <CategoriesSchedules schedules={SCHEDULES.slice(0, 8)} />
      <Partners />
      <WhyUs />
      <Testimonials items={TESTIMONIALS} />
      <Gallery />
      <LatestArticles articles={LATEST_ARTICLES} />
      <CtaBanner />
    </div>
  );
}
