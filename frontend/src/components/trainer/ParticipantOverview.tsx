import { ProgressBar } from "@/components/lms/ui";
import { MetaList, PortalCard } from "@/components/portal/ui";
import { type ParticipantOverview as Overview, pct, PRACTICE_PILL } from "@/lib/monitoring";

/**
 * Progress overview satu peserta (PRD §5.9): kehadiran, materi, nilai, praktik, kompetensi, catatan trainer.
 * Dipakai portal peserta (/portal/progres, hanya data tervalidasi) dan trainer (/trainer/progres/[code]/[id]).
 */
export default function ParticipantOverview({ o, forTrainer = false }: { o: Overview; forTrainer?: boolean }) {
  const comp = o.competency;
  return (
    <>
      <div className="kic pstats">
        <div className="kic1">
          <small>Kehadiran</small>
          <b>{pct(o.attendance.percent)}</b>
          <small style={{ textTransform: "none", letterSpacing: 0 }}>
            {o.attendance.present}/{o.attendance.held} sesi berjalan
          </small>
        </div>
        <div className="kic1">
          <small>Materi LMS</small>
          <b>{o.learning.percent}%</b>
          <small style={{ textTransform: "none", letterSpacing: 0 }}>
            {o.learning.completed}/{o.learning.total} lesson
          </small>
        </div>
        <div className="kic1">
          <small>Nilai kuis / tugas</small>
          <b>
            {pct(o.scores.quizAvg)} / {pct(o.scores.assignmentAvg)}
          </b>
          <small style={{ textTransform: "none", letterSpacing: 0 }}>
            {o.scores.quizDone}/{o.scores.quizTotal} kuis, {o.scores.assignmentsGraded}/{o.scores.assignmentsTotal} tugas dinilai
          </small>
        </div>
        <div className="kic1">
          <small>Praktik</small>
          <b>
            {o.practice.completed} selesai
            {o.practice.partial ? `, ${o.practice.partial} sebagian` : ""}
          </b>
          <small style={{ textTransform: "none", letterSpacing: 0 }}>{o.practice.logged} pertemuan tercatat</small>
        </div>
        <div className="kic1">
          <small>Kompetensi</small>
          <b>{comp ? `${pct(comp.percent)}${comp.isCompetent !== null ? (comp.isCompetent ? ", Kompeten" : ", Belum kompeten") : ""}` : forTrainer ? "belum dinilai" : "menunggu penilaian"}</b>
          <small style={{ textTransform: "none", letterSpacing: 0 }}>{comp ? `${comp.statusLabel}${comp.assessedBy ? ` oleh ${comp.assessedBy}` : ""}` : "penilaian trainer"}</small>
        </div>
      </div>

      {o.learning.courses.length > 0 && (
        <PortalCard title="Penyelesaian materi">
          {o.learning.courses.map((c) => (
            <div key={c.slug} style={{ marginTop: 12 }}>
              <div className="pcard-h">
                <b>{c.title}</b>
                <span className={"jst " + (c.percent >= 100 ? "ok" : c.percent > 0 ? "wn" : "no")}>
                  {c.percent}% ({c.completed}/{c.total})
                </span>
              </div>
              <ProgressBar percent={c.percent} label={c.title} />
            </div>
          ))}
        </PortalCard>
      )}

      <PortalCard title="Pencapaian kompetensi" action={comp ? <span className={"jst " + (comp.isCompetent ? "ok" : comp.isCompetent === false ? "no" : "wn")}>{comp.statusLabel}</span> : undefined}>
        {comp ? (
          <>
            <MetaList
              compact
              items={[
                { icon: "check", label: "Rubrik", value: comp.rubric.name },
                { label: "Ambang kompeten", value: `${comp.threshold}%` },
                { icon: "cal", label: "Dinilai", value: comp.assessedLabel },
                { label: "Disahkan", value: comp.validatedBy },
              ]}
            />
            <ProgressBar percent={comp.percent ?? 0} label="Total kompetensi" />
            <ul className="sp">
              {comp.criteria.map((c) => (
                <li key={c.id}>
                  <span className="spl">
                    <b>{c.name}</b>
                    {c.competency ? <small>Kompetensi: {c.competency}</small> : null}
                    {c.note ? <small>{c.note}</small> : null}
                  </span>
                  <b>
                    {c.score === null ? "-" : `${c.score}/${c.maxScore}`}
                    {c.levelLabel ? <span className={"jst " + (c.maxScore > 0 && c.score !== null && c.score / c.maxScore >= 0.75 ? "ok" : c.score !== null && c.score / c.maxScore >= 0.5 ? "wn" : "no")} style={{ marginLeft: 8 }}>{c.levelLabel}</span> : null}
                  </b>
                </li>
              ))}
            </ul>
            {comp.summary && (
              <p style={{ fontSize: 14.5 }}>
                <b>Umpan balik:</b> {comp.summary}
              </p>
            )}
            {comp.recommendation && (
              <p style={{ fontSize: 14.5, marginTop: 6 }}>
                <b>Rekomendasi:</b> {comp.recommendation}
              </p>
            )}
          </>
        ) : (
          <p className="fh">{forTrainer ? "Belum ada penilaian kompetensi untuk peserta ini." : "Penilaian kompetensi akan tampil setelah disahkan oleh trainer."}</p>
        )}
      </PortalCard>

      <PortalCard title="Catatan trainer per pertemuan">
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
                    forTrainer && n.status !== "validated" && { value: "draf" },
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
          <p className="fh">{forTrainer ? "Belum ada catatan logbook yang ditautkan ke peserta ini." : "Belum ada catatan trainer yang disahkan."}</p>
        )}
        {o.logbooks.length > 0 && (
          <>
            <p className="fh" style={{ marginTop: 14, fontWeight: 800 }}>
              Pertemuan tercatat
            </p>
            <ul className="sp" style={{ marginTop: 6, marginBottom: 0 }}>
              {o.logbooks.map((l) => (
                <li key={l.id}>
                  <span className="spl">
                    <b>{l.topic}</b>
                    <small>{l.dateLabel}</small>
                  </span>
                  <b>
                    {l.competencies.slice(0, 3).map((c) => (
                      <span className="chip" key={c}>
                        {c}
                      </span>
                    ))}
                  </b>
                </li>
              ))}
            </ul>
          </>
        )}
      </PortalCard>
    </>
  );
}
