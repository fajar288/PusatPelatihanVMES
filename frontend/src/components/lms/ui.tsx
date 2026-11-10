import Link from "next/link";
import { type CourseCard, LESSON_ICON, type LessonRow, PROGRESS_LABEL } from "@/lib/lms";

/** Bar progres hijau tema. */
export function ProgressBar({ percent, label }: { percent: number; label?: string }) {
  return (
    <div className="lpb" role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100} aria-label={label ?? "Progres"}>
      <span style={{ width: `${Math.min(100, Math.max(0, percent))}%` }} />
    </div>
  );
}

/** Kartu course pada "Belajar" & "Progres Saya". */
export function CourseRow({ c }: { c: CourseCard }) {
  return (
    <div className="kbx lcourse">
      {c.thumbnail ? (
        <img src={c.thumbnail} alt="" className="lthumb" />
      ) : (
        <div className="lthumb lthumb-ph" aria-hidden="true">
          {LESSON_ICON.document}
        </div>
      )}
      <div className="lcourse-b">
        <div className="pcard-h">
          <h3>{c.title}</h3>
          <span className={"jst " + (c.percent >= 100 ? "ok" : c.percent > 0 ? "wn" : "no")}>{c.percent >= 100 ? "Selesai" : `${c.percent}%`}</span>
        </div>
        <p className="fh" style={{ marginTop: 4 }}>
          {c.class.title} • {c.class.code} • {c.class.dateLabel} – {c.class.endLabel} • {c.modulesCount} modul • {c.lessonsCount} lesson
        </p>
        {c.description && <p style={{ marginTop: 8, fontSize: 14.5 }}>{c.description}</p>}
        <ProgressBar percent={c.percent} label={`Progres ${c.title}`} />
        <div className="lcourse-f">
          <small className="fh">
            {c.completed}/{c.lessonsCount} lesson selesai
          </small>
          {c.access ? (
            <Link className="jb" href={`/portal/belajar/${c.slug}`}>
              {c.percent > 0 ? "Lanjutkan" : "Mulai belajar"}
            </Link>
          ) : (
            <span className="fh">{c.accessReason}</span>
          )}
        </div>
      </div>
    </div>
  );
}

/** Baris lesson dalam daftar modul. */
export function LessonItem({ l, course, active }: { l: LessonRow; course: string; active?: boolean }) {
  const p = PROGRESS_LABEL[l.status];
  const inner = (
    <>
      <span className="lles-ic" aria-hidden="true">
        {LESSON_ICON[l.type]}
      </span>
      <span className="lles-t">
        <b>{l.title}</b>
        <small>
          {l.typeLabel}
          {l.durationMinutes ? ` • ${l.durationMinutes} menit` : ""}
          {!l.isRequired ? " • opsional" : ""}
          {!l.available && l.availableFrom ? ` • tersedia ${l.availableFrom}` : ""}
        </small>
      </span>
      <span className={"jst " + p.cls}>{p.label}</span>
    </>
  );
  return (
    <li className={"lles" + (active ? " on" : "") + (l.available ? "" : " off")}>
      {l.available ? <Link href={`/portal/belajar/${course}/${l.id}`}>{inner}</Link> : <span>{inner}</span>}
    </li>
  );
}
