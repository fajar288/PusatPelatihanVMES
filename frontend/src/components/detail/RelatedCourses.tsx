import Link from "next/link";
import Icon from "@/components/ui/Icon";
import type { Course } from "@/data/courses";

type Props = {
  courses: Course[];
  /** "rt-d" = tampil di desktop (kolom kiri), "rt-m" = tampil di mobile (bawah sidebar). */
  variant: "rt-d" | "rt-m";
  title?: string;
};

/** div.rt > .rcg > a.rc — kartu "Training Terkait" di halaman detail (CSS .rc/.rci/.rcb dari tema asli). */
export default function RelatedCourses({ courses, variant, title = "Training Terkait" }: Props) {
  if (!courses.length) return null;
  return (
    <div className={"rt " + variant}>
      <h2>{title}</h2>
      <div className="rcg">
        {courses.map((c) => (
          <Link className="rc" key={c.slug} href={`/kursus/${c.slug}`}>
            <div className="rci">{c.image && <img alt={c.title} src={c.image} loading="lazy" />}</div>
            <div className="rcb">
              <h3>{c.title}</h3>
              <span>
                Lihat Detail <Icon name="arr" />
              </span>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}
