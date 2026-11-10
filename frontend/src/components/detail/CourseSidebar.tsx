import Link from "next/link";
import Icon from "@/components/ui/Icon";
import type { Course } from "@/data/courses";
import { formatRupiah, waLink } from "@/lib/utils";
import RelatedCourses from "./RelatedCourses";
import WhyBox from "./WhyBox";

type Props = { course: Course; related: Course[] };

/** aside.kdr > .ksd — sidebar sticky: kartu harga `.pcd` + "Kenapa Pilih PPTI?" + terkait (mobile). */
export default function CourseSidebar({ course, related }: Props) {
  const waText = `Halo, saya ingin info training ${course.title}`;
  return (
    <aside className="kdr">
      <div className="ksd" id="kd-side">
        <div className="kbx pcd" id="kd-sc">
          <p>Mulai dari</p>
          <div className="kpr">{formatRupiah(course.price)}</div>
          <div className="ksp">
            <div>
              <Icon name="clock" />
              <div>
                <small>Durasi</small>
                <b>{course.durationDays} Hari</b>
              </div>
            </div>
            <div>
              <Icon name="pin" />
              <div>
                <small>Mode</small>
                <b>{course.mode}</b>
              </div>
            </div>
            <div>
              <Icon name="award" />
              <div>
                <small>Sertifikat</small>
                <b>{course.certificate}</b>
              </div>
            </div>
            <div>
              <Icon name="check" />
              <div>
                <small>Status</small>
                <b>{course.statusLabel}</b>
              </div>
            </div>
          </div>
          <div className="kbt">
            <a className="kb1 wa" href={waLink(waText)} target="_blank" rel="noopener">
              Konsultasi via WhatsApp
            </a>
            <Link className="kb2" href="/jadwal">
              Lihat Jadwal Training
            </Link>
          </div>
        </div>
        <WhyBox />
        <RelatedCourses courses={related} variant="rt-m" />
      </div>
    </aside>
  );
}
