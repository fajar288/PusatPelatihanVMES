import Link from "next/link";
import { ProgressBar } from "@/components/lms/ui";
import { EmptyState, MetaList, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { CourseCard, CourseDetail, ResultsInfo } from "@/lib/lms";
import { type ParticipantOverview as Overview, pct, PRACTICE_PILL } from "@/lib/monitoring";
import type { EnrollmentInfo } from "@/lib/portal-types";

export const instant = false;

/** /portal/progres — My Progress: kehadiran per kelas, progres per course & modul, nilai kuis/tugas, praktik & kompetensi, catatan trainer (yang sudah disahkan). */
export default async function PortalProgressPage() {
  const { token } = await requireUser("/portal/progres");
  const [enrollments, courses, results, overview] = await Promise.all([
    api<{ data: EnrollmentInfo[] }>("/me/enrollments", { token }).then((r) => r.data),
    api<{ data: CourseCard[] }>("/me/learning", { token }).then((r) => r.data),
    api<{ data: ResultsInfo }>("/me/learning/results", { token }).then((r) => r.data),
    api<{ data: Overview[] }>("/me/progress", { token }).then((r) => r.data),
  ]);
  const competent = overview.filter((o) => o.competency?.isCompetent).length;
  const practiceDone = overview.reduce((n, o) => n + o.practice.completed, 0);
  const details = await Promise.all(
    courses.filter((c) => c.access).map((c) => api<{ data: CourseDetail }>(`/me/learning/${c.slug}`, { token }).then((r) => r.data).catch(() => null)),
  );
  const lessonsDone = courses.reduce((n, c) => n + c.completed, 0);
  const lessonsTotal = courses.reduce((n, c) => n + c.lessonsCount, 0);
  const quizBest = results.quizzes.filter((q) => q.best).map((q) => q.best!.percent ?? 0);
  const graded = results.assignments.filter((a) => a.submission?.status === "graded");
  const att = enrollments.map((e) => e.attendancePercent).filter((v): v is number => v !== null);

  return (
    <>
      <div className="kic pstats">
        <div className="kic1">
          <small>Kehadiran rata-rata</small>
          <b>{att.length ? `${Math.round(att.reduce((a, b) => a + b, 0) / att.length)}%` : "-"}</b>
        </div>
        <div className="kic1">
          <small>Materi selesai</small>
          <b>
            {lessonsDone}/{lessonsTotal}
          </b>
        </div>
        <div className="kic1">
          <small>Rata-rata kuis</small>
          <b>{quizBest.length ? `${Math.round(quizBest.reduce((a, b) => a + b, 0) / quizBest.length)}%` : "-"}</b>
        </div>
        <div className="kic1">
          <small>Tugas dinilai</small>
          <b>
            {graded.length}/{results.assignments.length}
          </b>
        </div>
        <div className="kic1">
          <small>Praktik selesai</small>
          <b>{practiceDone}</b>
        </div>
        <div className="kic1">
          <small>Kompetensi</small>
          <b>{overview.some((o) => o.competency) ? `${competent}/${overview.filter((o) => o.competency).length} kompeten` : "menunggu"}</b>
        </div>
      </div>

      <PortalCard title="Progres Saya">
        {courses.length === 0 && <EmptyState text="Belum ada course yang bisa dipantau." href="/portal/belajar" cta="Ke halaman Belajar" />}
        {courses.map((c, i) => {
          const d = details[courses.filter((x) => x.access).indexOf(c)] ?? null;
          const enr = enrollments.find((e) => e.id === c.enrollmentId);
          return (
            <div className="kbx" key={c.slug} style={{ marginTop: i === 0 ? 16 : 18, padding: 22 }}>
              <div className="pcard-h">
                <h3 style={{ fontSize: 18 }}>
                  <Link href={`/portal/belajar/${c.slug}`}>{c.title}</Link>
                </h3>
                <span className={"jst " + (c.percent >= 100 ? "ok" : c.percent > 0 ? "wn" : "no")}>{c.percent}%</span>
              </div>
              <MetaList
                compact
                items={[
                  { icon: "grad", value: c.class.title },
                  { icon: "code", value: c.class.code },
                  !!enr && { icon: "check", label: "Kehadiran", value: enr.attendancePercent === null ? "-" : `${enr.attendancePercent}%` },
                  enr?.finalScore != null && { icon: "award", label: "Nilai akhir", value: enr.finalScore },
                ]}
              />
              <ProgressBar percent={c.percent} />
              {d && (
                <ul className="sp" style={{ marginBottom: 0 }}>
                  {d.modules.map((m) => (
                    <li key={m.id}>
                      <span>{m.title}</span>
                      <b>
                        <span className={"jst " + (m.isCompleted ? "ok" : m.completed > 0 ? "wn" : "no")}>
                          {m.completed}/{m.lessonsCount}
                        </span>
                      </b>
                    </li>
                  ))}
                </ul>
              )}
              {!c.access && <p className="fh">{c.accessReason}</p>}
            </div>
          );
        })}
      </PortalCard>

      {(results.quizzes.length > 0 || results.assignments.length > 0) && (
        <PortalCard
          title="Nilai"
          action={
            <Link className="lk" href="/portal/hasil">
              Rincian hasil
            </Link>
          }
        >
          <ul className="sp">
            {results.quizzes.map((q) => (
              <li key={`q${q.lessonId}`}>
                <span>
                  {q.kindLabel}: {q.title}
                </span>
                <b>{q.best ? <span className={"jst " + (q.best.passed ? "ok" : "no")}>{q.best.percent}%</span> : <span className="fh">belum dikerjakan</span>}</b>
              </li>
            ))}
            {results.assignments.map((a) => (
              <li key={`a${a.lessonId}`}>
                <span>Tugas: {a.title}</span>
                <b>{a.submission?.status === "graded" ? `${a.submission.score}/${a.maxScore}` : <span className="fh">{a.submission ? a.submission.statusLabel : "belum dikumpulkan"}</span>}</b>
              </li>
            ))}
          </ul>
        </PortalCard>
      )}

      {overview.map((o) => (
        <PortalCard
          key={o.enrollment.id}
          title={`Praktik, kompetensi & catatan trainer — ${o.class.title}`}
          action={
            o.competency ? (
              <span className={"jst " + (o.competency.isCompetent ? "ok" : o.competency.isCompetent === false ? "no" : "wn")}>
                {o.competency.isCompetent ? "Kompeten" : "Belum kompeten"} ({pct(o.competency.percent)})
              </span>
            ) : (
              <span className="jst wn">Penilaian belum disahkan</span>
            )
          }
        >
          <MetaList
            items={[
              { icon: "code", value: o.class.code },
              { icon: "check", label: "Kehadiran", value: `${pct(o.attendance.percent)} (${o.attendance.present}/${o.attendance.held} sesi)` },
              {
                icon: "wrench",
                label: "Praktik",
                value: [`${o.practice.completed} selesai`, o.practice.partial ? `${o.practice.partial} sebagian` : "", o.practice.notDone ? `${o.practice.notDone} belum` : ""].filter(Boolean).join(", "),
              },
              { icon: "brief", label: "Logbook", value: `${o.practice.logged} pertemuan` },
            ]}
          />
          {o.competency && (
            <>
              <p className="fh" style={{ marginTop: 8, fontWeight: 800 }}>
                Pencapaian kompetensi ({o.competency.rubric.name}; ambang {o.competency.threshold}%)
              </p>
              <ul className="sp" style={{ marginTop: 6 }}>
                {o.competency.criteria.map((c) => (
                  <li key={c.id}>
                    <span className="spl">
                      <b>{c.name}</b>
                      {c.note ? <small>{c.note}</small> : null}
                    </span>
                    <b className="spv">
                      {c.score === null ? "-" : `${c.score}/${c.maxScore}`}
                      {c.levelLabel ? <small>{c.levelLabel}</small> : null}
                    </b>
                  </li>
                ))}
              </ul>
              {o.competency.summary && (
                <p style={{ fontSize: 14.5 }}>
                  <b>Umpan balik trainer:</b> {o.competency.summary}
                </p>
              )}
              {o.competency.recommendation && (
                <p style={{ fontSize: 14.5, marginTop: 6 }}>
                  <b>Rekomendasi:</b> {o.competency.recommendation}
                </p>
              )}
            </>
          )}
          <p className="fh" style={{ marginTop: 14, fontWeight: 800 }}>
            Catatan trainer per pertemuan
          </p>
          {o.notes.length ? (
            <ul className="pnotif">
              {o.notes.map((n) => (
                <li key={n.logbookId}>
                  <MetaList
                    compact
                    style={{ marginTop: 0 }}
                    items={[
                      { icon: "cal", value: n.dateLabel },
                      { value: n.topic },
                      { icon: "users", value: n.trainer },
                    ]}
                  />
                  <p>
                    {n.practiceStatus ? (
                      <span className={"jst " + PRACTICE_PILL[n.practiceStatus]} style={{ marginRight: 8 }}>
                        {n.practiceLabel}
                      </span>
                    ) : null}
                    {n.observation ?? ""}
                  </p>
                </li>
              ))}
            </ul>
          ) : (
            <p className="fh">Belum ada catatan trainer yang disahkan untuk kelas ini.</p>
          )}
        </PortalCard>
      ))}
    </>
  );
}
