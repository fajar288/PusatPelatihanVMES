import { CourseRow } from "@/components/lms/ui";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { CourseCard } from "@/lib/lms";

export const instant = false;

/** /portal/belajar — My Learning: course yang bisa diakses dari kelas terdaftar (GET /me/learning). */
export default async function PortalLearningPage() {
  const { token } = await requireUser("/portal/belajar");
  const courses = (await api<{ data: CourseCard[] }>("/me/learning", { token })).data;
  const active = courses.filter((c) => c.percent < 100);
  const done = courses.filter((c) => c.percent >= 100);

  return (
    <>
      <PortalCard title="Belajar">
        {courses.length === 0 && <EmptyState text="Belum ada materi belajar. Materi tersedia setelah pendaftaran dikonfirmasi dan kelas menyediakan course online." href="/portal/pelatihan" cta="Lihat Pelatihan Saya" />}
        {active.map((c) => (
          <CourseRow key={c.slug} c={c} />
        ))}
      </PortalCard>
      {done.length > 0 && (
        <PortalCard title="Sudah selesai">
          {done.map((c) => (
            <CourseRow key={c.slug} c={c} />
          ))}
        </PortalCard>
      )}
    </>
  );
}
