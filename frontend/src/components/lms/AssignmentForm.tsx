"use client";

import { useActionState } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { type FormState } from "@/lib/actions/auth";
import { submitAssignmentAction } from "@/lib/actions/lms";
import type { LessonDetail } from "@/lib/lms";

/** Form pengumpulan tugas (teks dan/atau berkas) + status/nilai/umpan balik. */
export default function AssignmentForm({ lesson }: { lesson: LessonDetail }) {
  const a = lesson.assignment!;
  const [state, action, pending] = useActionState<FormState, FormData>(submitAssignmentAction, { ok: false });
  const sub = a.submission;
  const locked = sub?.status === "graded" || (a.overdue && !sub);

  return (
    <div>
      <ul className="sp" style={{ marginTop: 0 }}>
        <li>
          <span>Batas waktu</span>
          <b>{a.dueLabel ?? "Tidak ada"}</b>
        </li>
        <li>
          <span>Nilai maksimal</span>
          <b>{a.maxScore}</b>
        </li>
        {sub && (
          <>
            <li>
              <span>Status</span>
              <b>
                <span className="spv">
                  <span className={"jst " + (sub.status === "graded" ? "ok" : sub.status === "returned" ? "no" : "wn")}>{sub.statusLabel}</span>
                  <small>{sub.submittedLabel}</small>
                </span>
              </b>
            </li>
            {sub.score !== null && (
              <li>
                <span>Nilai</span>
                <b className="spv">
                  {sub.score} / {a.maxScore}
                  {sub.gradedBy && <small>Dinilai oleh {sub.gradedBy}</small>}
                </b>
              </li>
            )}
            {sub.feedback && (
              <li>
                <span>Umpan balik</span>
                <b style={{ fontWeight: 500 }}>{sub.feedback}</b>
              </li>
            )}
            {sub.hasFile && (
              <li>
                <span>Berkas</span>
                <b>
                  <a href={`/portal/belajar/tugas-berkas/${sub.id}`}>{sub.fileName}</a>
                </b>
              </li>
            )}
          </>
        )}
      </ul>
      {locked ? (
        <p className="fh">{sub?.status === "graded" ? "Tugas sudah dinilai." : "Batas waktu pengumpulan sudah lewat."}</p>
      ) : (
        <form className="ff" action={action} style={{ marginTop: 16 }}>
          <input type="hidden" name="course" value={lesson.course.slug} />
          <input type="hidden" name="lesson" value={lesson.id} />
          {a.allowText && (
            <div>
              <label htmlFor="as-text">Jawaban</label>
              <textarea id="as-text" name="text" rows={6} defaultValue={sub?.text ?? ""} placeholder="Tulis jawaban atau penjelasan singkat…" />
            </div>
          )}
          {a.allowFile && (
            <div>
              <label htmlFor="as-file">
                Berkas <small>(PDF/Office/ZIP/gambar, maks. 20 MB)</small>
              </label>
              <input id="as-file" name="file" type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.rar,.jpg,.jpeg,.png,.txt,.csv,.mp4" />
            </div>
          )}
          <FormMessage ok={state.ok} message={state.message} />
          <button className="kb1" type="submit" disabled={pending}>
            {pending ? "Mengirim…" : sub ? "Kirim ulang tugas" : "Kumpulkan tugas"}
          </button>
        </form>
      )}
    </div>
  );
}
