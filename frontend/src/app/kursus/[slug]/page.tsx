import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import CourseSchedules from "@/components/detail/CourseSchedules";
import CourseSidebar from "@/components/detail/CourseSidebar";
import RelatedCourses from "@/components/detail/RelatedCourses";
import Icon from "@/components/ui/Icon";
import { COURSES, getCourse, getRelatedCourses } from "@/data/courses";
import { SCHEDULES } from "@/data/schedules";
import { SITE } from "@/data/site";
import { abs, JsonLd } from "@/lib/seo";

export function generateStaticParams() {
  return COURSES.map((c) => ({ slug: c.slug }));
}

export async function generateMetadata({ params }: PageProps<"/kursus/[slug]">): Promise<Metadata> {
  const { slug } = await params;
  const course = getCourse(slug);
  if (!course) return { title: `Training tidak ditemukan | ${SITE.name}` };
  return {
    title: `${course.title} | ${SITE.name}`,
    description: course.desc,
    alternates: { canonical: `/kursus/${course.slug}` },
    openGraph: { title: course.title, description: course.desc, type: "website", locale: "id_ID" },
  };
}

/**
 * /kursus/[slug] — detail training. Website lama belum punya halaman ini (semua "Lihat Detail" → #),
 * tetapi CSS-nya sudah ada (.kd .kic .kjl .kov .ksd .pcd .rcg). Layout mengikuti single post:
 * back link → chips → h1 → ringkasan → info chips → grid [konten | sidebar sticky].
 */
export default async function KursusDetailPage({ params }: PageProps<"/kursus/[slug]">) {
  const { slug } = await params;
  const course = getCourse(slug);
  if (!course) notFound();

  const schedules = SCHEDULES.filter((s) => s.courseSlug === course.slug);
  const related = getRelatedCourses(course);
  const ov = course.overview;
  const courseLd = {
    "@context": "https://schema.org",
    "@type": "Course",
    name: course.title,
    description: course.desc,
    url: abs(`/kursus/${course.slug}`),
    provider: { "@type": "Organization", name: SITE.company, url: abs("/") },
    educationalLevel: course.level,
    offers: { "@type": "Offer", price: course.price, priceCurrency: "IDR", category: course.category },
    hasCourseInstance: schedules.map((s) => ({ "@type": "CourseInstance", courseMode: s.mode, startDate: s.startDate, endDate: s.endDate, location: s.city })),
  };

  return (
    <div className="g-kursus">
      <JsonLd data={courseLd} />
      <section className="kd" id="kd">
        <div className="wrap">
          <Link className="bk" href="/kursus" id="kd-back">
            <Icon name="arrl" />
            Kembali ke Katalog
          </Link>
          <div className="chips" id="kd-chips">
            <Link className="chp a" href={`/kursus?kategori=${encodeURIComponent(course.category)}`}>
              {course.category}
            </Link>
            <span className="chp">{course.vendor}</span>
            {course.popular && <span className="chp o">Populer</span>}
          </div>
          <h1>{course.title}</h1>
          <div className="dmeta">{course.level}</div>
          <p className="rs" id="kd-rs">
            {course.desc}
          </p>
          {course.image && <img className="kdi" alt={course.title} src={course.image} style={{ marginTop: 28 }} />}

          <div className="kic">
            <div className="kic1">
              <Icon name="clock" />
              <small>Durasi</small>
              <b>{course.durationDays} Hari</b>
            </div>
            <div className="kic1">
              <Icon name="pin" />
              <small>Mode</small>
              <b>{course.mode}</b>
            </div>
            <div className="kic1">
              <Icon name="chart" />
              <small>Level</small>
              <b>{course.level}</b>
            </div>
            <div className="kic1">
              <Icon name="award" />
              <small>Sertifikat</small>
              <b>{course.certificate}</b>
            </div>
          </div>

          <div className="kdg">
            <div className="kdl">
              <CourseSchedules schedules={schedules} title={course.title} />

              <section className="kbx">
                <h2 id="kd-oh">Overview</h2>
                <div className="kov" id="kd-ov">
                  <div className="pb">
                    {ov.intro.map((p) => (
                      <p key={p}>{p}</p>
                    ))}
                    <h3>Yang Akan Dipelajari</h3>
                    <ul>
                      {ov.learn.map((t) => (
                        <li key={t}>{t}</li>
                      ))}
                    </ul>
                    <h3>Untuk Siapa Training Ini</h3>
                    <ul>
                      {ov.audience.map((t) => (
                        <li key={t}>{t}</li>
                      ))}
                    </ul>
                    <h3>Persyaratan Peserta</h3>
                    <ul>
                      {ov.requirements.map((t) => (
                        <li key={t}>{t}</li>
                      ))}
                    </ul>
                  </div>
                </div>
              </section>

              <RelatedCourses courses={related} variant="rt-d" />
            </div>

            <CourseSidebar course={course} related={related} />
          </div>
        </div>
      </section>
    </div>
  );
}
