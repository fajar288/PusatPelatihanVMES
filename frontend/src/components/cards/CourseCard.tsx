import Link from "next/link";
import Icon from "@/components/ui/Icon";
import type { Course } from "@/data/courses";
import { formatRupiah } from "@/lib/utils";

/** article.cc — kartu kursus (slider beranda & katalog). Markup identik dengan tema asli. */
export default function CourseCard({ course }: { course: Course }) {
  const href = `/kursus/${course.slug}`;
  return (
    <article className="cc">
      <Link href={href}>
        <div className="ci">
          {course.image ? <img alt={course.title} src={course.image} loading="lazy" /> : <div className="noimg" />}
          <span className="b1">{course.category}</span>
          {course.popular && <span className="b2">Populer</span>}
        </div>
      </Link>
      <div className="cp">
        <p className="br">{course.vendor}</p>
        <Link href={href}>
          <h3>{course.title}</h3>
        </Link>
        <p className="ds">{course.desc}</p>
        <div className="mt">
          <span>
            {/* ikon jam & pin inline (bukan sprite), persis seperti tema asli */}
            <svg viewBox="0 0 24 24">
              <circle cx="12" cy="12" r="10" />
              <path d="M12 6v6l4 2" />
            </svg>
            {course.durationDays} Hari
          </span>
          <span>
            <svg viewBox="0 0 24 24">
              <path d="M20 10c0 5-5.5 10.2-7.4 11.8a1 1 0 0 1-1.2 0C9.5 20.2 4 15 4 10a8 8 0 0 1 16 0" />
              <circle cx="12" cy="10" r="3" />
            </svg>
            {course.mode}
          </span>
          <span className={"st " + course.status}>{course.statusLabel}</span>
        </div>
        <div className="pr">
          <div>
            <small>Mulai dari</small>
            <b>{formatRupiah(course.price)}</b>
          </div>
          <Link href={href}>
            Lihat Detail <Icon name="arr" />
          </Link>
        </div>
      </div>
    </article>
  );
}
