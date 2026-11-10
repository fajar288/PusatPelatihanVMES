import Link from "next/link";
import { notFound } from "next/navigation";
import { PortalCard } from "@/components/portal/ui";
import { api, ApiRequestError } from "@/lib/auth";
import { LESSON_ICON, type TrainerLearning } from "@/lib/lms";
import { requireTrainer } from "@/lib/trainer";

export const instant = false;

/** /trainer/materi/[code] — materi kelas (course → modul → lesson) + tabel progres LMS tiap peserta. */
export default async function TrainerClassMaterials({ params }: PageProps<"/trainer/materi/[code]">) {
  const { code } = await params;
  const { token } = await requireTrainer(`/trainer/materi/${code}`);
  let d: TrainerLearning;
  try {
    d = (await api<{ data: TrainerLearning }>(`/trainer/classes/${encodeURIComponent(code)}/learning`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    throw e;
  }

  return (
    <>
      <Link className="bk" href="/trainer/materi">
        ← Materi
      </Link>
      <PortalCard
        title={`Progres belajar — ${d.class.title}`}
        action={
          <span style={{ display: "flex", gap: 8 }}>
            <Link className="jb" href={`/trainer/tugas/${code}`}>
              Tugas
            </Link>
            <Link className="jb" href={`/trainer/kuis/${code}`}>
              Hasil kuis
            </Link>
          </span>
        }
      >
        {d.participants.length === 0 ? (
          <p className="fh">Belum ada peserta.</p>
        ) : (
          <table className="att" style={{ marginTop: 12 }}>
            <thead>
              <tr>
                <th>Peserta</th>
                <th>Kehadiran</th>
                <th>Materi</th>
                <th>Kuis</th>
                <th>Tugas</th>
                <th>Terakhir aktif</th>
              </tr>
            </thead>
            <tbody>
              {d.participants.map((p) => (
                <tr key={p.enrollment_id}>
                  <td>
                    <b>{p.name}</b>
                    <small>{p.email}</small>
                  </td>
                  <td>{p.attendance_percent === null ? "-" : `${p.attendance_percent}%`}</td>
                  <td>
                    <b>{p.percent}%</b>
                    <small>
                      {p.lessons_completed}/{p.lessons_total} lesson
                    </small>
                  </td>
                  <td>
                    <b>{p.quiz_avg === null ? "-" : `${p.quiz_avg}%`}</b>
                    <small>
                      {p.quiz_done}/{p.quiz_total} dikerjakan
                    </small>
                  </td>
                  <td>
                    <b>
                      {p.assignments_submitted}/{p.assignments_total}
                    </b>
                    <small>{p.assignments_graded} dinilai</small>
                  </td>
                  <td>{p.last_seen_at ? new Date(p.last_seen_at).toLocaleDateString("id-ID", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "-"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </PortalCard>

      {d.courses.length === 0 && (
        <PortalCard title="Materi">
          <p className="fh">Belum ada course untuk program kelas ini. Minta admin membuat course di panel admin (menu LMS).</p>
        </PortalCard>
      )}
      {d.courses.map((c) => (
        <PortalCard key={c.slug} title={c.title} action={<span className="jst ok">{c.lessonsCount} lesson</span>}>
          {c.description && <p className="fh">{c.description}</p>}
          <p className="fh" style={{ marginTop: 4 }}>
            Akses: {c.availableBeforeClass ? "sebelum kelas mulai" : "sejak kelas mulai"} • setelah kelas: {c.accessDaysAfter === null ? "selamanya" : `${c.accessDaysAfter} hari`}
          </p>
          {c.modules.map((m, i) => (
            <div key={m.id} style={{ marginTop: 14 }}>
              <b>
                Modul {i + 1}: {m.title}
              </b>
              <ul className="lles-l">
                {m.lessons.map((l) => (
                  <li className="lles" key={l.id}>
                    <span>
                      <span className="lles-ic" aria-hidden="true">
                        {LESSON_ICON[l.type]}
                      </span>
                      <span className="lles-t">
                        <b>{l.title}</b>
                        <small>
                          {l.typeLabel}
                          {l.durationMinutes ? ` • ${l.durationMinutes} menit` : ""}
                          {!l.isRequired ? " • opsional" : ""}
                          {l.resources.length ? ` • ${l.resources.length} berkas` : ""}
                        </small>
                      </span>
                      {l.resources.some((r) => r.type === "file") && (
                        <span style={{ display: "flex", gap: 8 }}>
                          {l.resources
                            .filter((r) => r.type === "file")
                            .map((r) => (
                              <a key={r.id} className="lk" style={{ marginTop: 0 }} href={`/portal/belajar/berkas/${r.id}`}>
                                {r.fileName}
                              </a>
                            ))}
                        </span>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </PortalCard>
      ))}
    </>
  );
}
