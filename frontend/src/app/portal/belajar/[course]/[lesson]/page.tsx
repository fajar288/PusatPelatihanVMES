import Link from "next/link";
import { notFound } from "next/navigation";
import AssignmentForm from "@/components/lms/AssignmentForm";
import CompleteButton from "@/components/lms/CompleteButton";
import Discussion from "@/components/lms/Discussion";
import QuizRunner from "@/components/lms/QuizRunner";
import VideoPlayer from "@/components/lms/VideoPlayer";
import { PortalCard } from "@/components/portal/ui";
import { api, ApiRequestError, requireUser } from "@/lib/auth";
import { type DiscussionPostInfo, LESSON_ICON, type LessonDetail, type ResourceInfo } from "@/lib/lms";

export const instant = false;

/** /portal/belajar/[course]/[lesson] — konten lesson: materi/dokumen/video/tautan/kuis/tugas + berkas + diskusi. */
export default async function LessonPage({ params }: PageProps<"/portal/belajar/[course]/[lesson]">) {
  const { course, lesson } = await params;
  const back = `/portal/belajar/${course}/${lesson}`;
  const { token } = await requireUser(back);
  let l: LessonDetail;
  try {
    l = (await api<{ data: LessonDetail }>(`/me/learning/${encodeURIComponent(course)}/lessons/${encodeURIComponent(lesson)}`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && e.error.status === 404) notFound();
    if (e instanceof ApiRequestError && e.error.status === 403) {
      return (
        <>
          <Link className="bk" href={`/portal/belajar/${course}`}>
            ← Kembali ke course
          </Link>
          <PortalCard title="Materi belum dapat diakses">
            <p className="fh">{e.error.message}</p>
          </PortalCard>
        </>
      );
    }
    throw e;
  }
  const posts = (await api<{ data: DiscussionPostInfo[] }>(`/me/learning/${encodeURIComponent(course)}/discussions?lesson=${l.id}`, { token })).data;
  const canMark = ["text", "document", "link", "video"].includes(l.type);

  return (
    <>
      <Link className="bk" href={`/portal/belajar/${course}`}>
        ← {l.course.title}
      </Link>
      <PortalCard
        title={`${LESSON_ICON[l.type]} ${l.title}`}
        action={canMark ? <CompleteButton course={course} lesson={l.id} done={l.progress.status === "completed"} /> : <span className={"jst " + (l.progress.status === "completed" ? "ok" : "wn")}>{l.progress.status === "completed" ? "Selesai" : l.typeLabel}</span>}
      >
        <p className="fh" style={{ marginTop: 4 }}>
          {l.module.title} • {l.typeLabel}
          {l.durationMinutes ? ` • ${l.durationMinutes} menit` : ""}
          {!l.isRequired ? " • opsional" : ""}
        </p>

        {l.type === "video" && <VideoPlayer lesson={l} />}

        {l.body && <div className="pb lbody" dangerouslySetInnerHTML={{ __html: l.body }} />}

        {l.type === "link" && l.link?.url && (
          <p style={{ marginTop: 16 }}>
            <a className="btn" href={l.link.url} target="_blank" rel="noopener noreferrer">
              Buka tautan ↗
            </a>
          </p>
        )}

        {l.resources.length > 0 && (
          <div style={{ marginTop: 20 }}>
            <h3 style={{ fontSize: 16, marginBottom: 8 }}>Berkas & tautan</h3>
            <ResourceList items={l.resources} />
          </div>
        )}
      </PortalCard>

      {l.type === "quiz" && l.quiz && (
        <PortalCard title={l.quiz.kindLabel}>
          <QuizRunner course={course} lesson={l.id} initial={l.quiz} />
        </PortalCard>
      )}

      {l.type === "assignment" && l.assignment && (
        <PortalCard title="Pengumpulan tugas">
          <AssignmentForm lesson={l} />
        </PortalCard>
      )}

      <nav className="lnav">
        {l.prev ? (
          <Link className="jb" href={`/portal/belajar/${course}/${l.prev.id}`}>
            ← {l.prev.title}
          </Link>
        ) : (
          <span />
        )}
        {l.next ? (
          <Link className="jb" href={`/portal/belajar/${course}/${l.next.id}`}>
            {l.next.title} →
          </Link>
        ) : (
          <Link className="jb" href={`/portal/belajar/${course}`}>
            Selesai, kembali ke course
          </Link>
        )}
      </nav>

      <PortalCard title="Tanya jawab materi ini">
        <Discussion course={course} lesson={l.id} posts={posts} back={back} />
      </PortalCard>
    </>
  );
}

/** Viewer PDF/gambar/video inline + tautan unduh bila diizinkan. Office dibuka/diunduh (viewer online butuh URL publik). */
function ResourceList({ items }: { items: ResourceInfo[] }) {
  return (
    <ul className="lres-l">
      {items.map((r) => {
        const view = `/portal/belajar/berkas/${r.id}?inline=1`;
        const dl = `/portal/belajar/berkas/${r.id}`;
        return (
          <li key={r.id}>
            <div className="lres-h">
              <b>{r.title}</b>
              <small className="fh">
                {r.type === "link" ? "Tautan" : `${r.fileName ?? ""}${r.sizeLabel ? ` • ${r.sizeLabel}` : ""}`}
                {r.type === "file" && !r.allowDownload ? " • hanya baca" : ""}
              </small>
              <span>
                {r.type === "link" && r.url && (
                  <a className="lk" href={r.url} target="_blank" rel="noopener noreferrer">
                    Buka ↗
                  </a>
                )}
                {r.type === "file" && (r.kind === "pdf" || r.kind === "image" || r.kind === "video" || r.kind === "audio") && (
                  <a className="lk" href={view} target="_blank" rel="noopener noreferrer">
                    Buka
                  </a>
                )}
                {r.type === "file" && r.allowDownload && (
                  <a className="lk" href={dl}>
                    Unduh
                  </a>
                )}
                {r.type === "file" && r.kind === "office" && !r.allowDownload && <span className="fh">Hubungi trainer untuk akses dokumen Office.</span>}
              </span>
            </div>
            {r.kind === "pdf" && <iframe className="lres-v" src={view} title={r.title} />}
            {r.kind === "image" && (
              <img className="lres-img" src={view} alt={r.title} />
            )}
            {r.kind === "video" && <video className="lres-vid" controls preload="metadata" src={view} />}
            {r.kind === "audio" && <audio controls preload="metadata" src={view} style={{ width: "100%", marginTop: 8 }} />}
          </li>
        );
      })}
    </ul>
  );
}
