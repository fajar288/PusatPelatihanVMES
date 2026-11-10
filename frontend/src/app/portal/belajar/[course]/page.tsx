import Link from "next/link";
import { notFound } from "next/navigation";
import Discussion from "@/components/lms/Discussion";
import { LessonItem, ProgressBar } from "@/components/lms/ui";
import { PortalCard } from "@/components/portal/ui";
import { api, ApiRequestError, requireUser } from "@/lib/auth";
import type { CourseDetail, DiscussionPostInfo } from "@/lib/lms";

export const instant = false;

/** /portal/belajar/[course] — daftar modul & lesson dengan status progres + forum course. */
export default async function CoursePage({ params }: PageProps<"/portal/belajar/[course]">) {
  const { course } = await params;
  const { token } = await requireUser(`/portal/belajar/${course}`);
  let c: CourseDetail;
  let denied: string | null = null;
  try {
    c = (await api<{ data: CourseDetail }>(`/me/learning/${encodeURIComponent(course)}`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && e.error.status === 404) notFound();
    if (e instanceof ApiRequestError && e.error.status === 403) denied = e.error.message;
    else throw e;
    return (
      <>
        <Link className="bk" href="/portal/belajar">
          ← Belajar
        </Link>
        <PortalCard title="Materi belum dapat diakses">
          <p className="fh">{denied}</p>
        </PortalCard>
      </>
    );
  }
  const posts = (await api<{ data: DiscussionPostInfo[] }>(`/me/learning/${encodeURIComponent(course)}/discussions`, { token })).data;

  return (
    <>
      <Link className="bk" href="/portal/belajar">
        ← Belajar
      </Link>
      <PortalCard title={c.title} action={<span className={"jst " + (c.percent >= 100 ? "ok" : "wn")}>{c.percent}% selesai</span>}>
        <p className="fh" style={{ marginTop: 4 }}>
          {c.class.title} • {c.class.code} • {c.class.dateLabel} – {c.class.endLabel}
        </p>
        {c.description && <p style={{ marginTop: 10 }}>{c.description}</p>}
        <ProgressBar percent={c.percent} />
        <div className="lcourse-f">
          <small className="fh">
            {c.completed}/{c.lessonsCount} lesson • {c.requiredCompleted}/{c.requiredTotal} wajib selesai
          </small>
          {c.nextLesson && (
            <Link className="jb" href={`/portal/belajar/${c.slug}/${c.nextLesson.id}`}>
              {c.percent > 0 ? "Lanjutkan: " : "Mulai: "}
              {c.nextLesson.title}
            </Link>
          )}
        </div>
      </PortalCard>

      {c.modules.map((m, i) => (
        <PortalCard key={m.id} title={`Modul ${i + 1}: ${m.title}`} action={<span className={"jst " + (m.isCompleted ? "ok" : m.completed > 0 ? "wn" : "no")}>{m.completed}/{m.lessonsCount}</span>}>
          {m.description && <p className="fh">{m.description}</p>}
          <ul className="lles-l">
            {m.lessons.map((l) => (
              <LessonItem key={l.id} l={l} course={c.slug} />
            ))}
          </ul>
        </PortalCard>
      ))}

      <PortalCard title="Forum & tanya jawab">
        <Discussion course={c.slug} posts={posts} back={`/portal/belajar/${c.slug}`} />
      </PortalCard>
    </>
  );
}
