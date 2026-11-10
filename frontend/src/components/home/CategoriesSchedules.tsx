import Link from "next/link";
import CategoryCard from "@/components/cards/CategoryCard";
import Icon from "@/components/ui/Icon";
import { CATEGORIES } from "@/data/home";
import type { Schedule } from "@/data/schedules";
import SchedulePanel from "./SchedulePanel";

/** section.sec.alt > .jk — kiri kategori kompetensi (8 kartu), kanan jadwal terdekat. */
export default function CategoriesSchedules({ schedules }: { schedules: Schedule[] }) {
  return (
    <section className="sec alt">
      <div className="wrap jk">
        <div>
          <span className="eb">Kategori</span>
          <h2 className="tt">Pilih Jalur Kompetensi Sesuai Kebutuhan Anda</h2>
          <div className="cg">
            {CATEGORIES.map((c) => (
              <CategoryCard key={c.title} {...c} />
            ))}
          </div>
        </div>
        <div>
          <span className="eb">Jadwal</span>
          <h2 className="tt">Jadwal Terdekat</h2>
          <Link className="lk" href="/jadwal">
            Lihat semua jadwal <Icon name="arr" />
          </Link>
          <SchedulePanel schedules={schedules} />
        </div>
      </div>
    </section>
  );
}
