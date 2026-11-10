"use client";

import { useActionState, useState } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { type FormState } from "@/lib/actions/auth";
import { gradeSubmissionAction } from "@/lib/actions/lms";
import type { TrainerSubmission } from "@/lib/lms";

/** Satu kartu pengumpulan tugas + form nilai/umpan balik untuk trainer. */
export default function GradeForm({ s, code }: { s: TrainerSubmission; code: string }) {
  const [state, action, pending] = useActionState<FormState, FormData>(gradeSubmissionAction, { ok: false });
  const [open, setOpen] = useState(s.status === "submitted");

  return (
    <article className="kbx lsub">
      <div className="pcard-h">
        <h3 style={{ fontSize: 17 }}>
          {s.participant.name} <small className="fh">• {s.lessonTitle}</small>
        </h3>
        <span className={"jst " + (s.status === "graded" ? "ok" : s.status === "returned" ? "no" : "wn")}>
          {s.statusLabel}
          {s.score != null ? ` • ${s.score}/${s.maxScore}` : ""}
        </span>
      </div>
      <p className="fh" style={{ marginTop: 4 }}>
        {s.course} • dikumpulkan {s.submittedLabel}
        {s.gradedBy ? ` • dinilai ${s.gradedBy}` : ""}
      </p>
      {s.text && <blockquote className="lsub-t">{s.text}</blockquote>}
      {s.hasFile && (
        <p style={{ marginTop: 8 }}>
          <a className="lk" href={`/trainer/tugas/berkas/${s.id}`}>
            Unduh berkas: {s.fileName}
          </a>
        </p>
      )}
      {s.feedback && !open && (
        <p className="fh" style={{ marginTop: 8 }}>
          Umpan balik: {s.feedback}
        </p>
      )}
      {open ? (
        <form className="ff" action={action} style={{ marginTop: 14 }}>
          <input type="hidden" name="id" value={s.id} />
          <input type="hidden" name="code" value={code} />
          <div className="fg2">
            <div>
              <label htmlFor={`st-${s.id}`}>Keputusan</label>
              <select id={`st-${s.id}`} name="status" defaultValue="graded">
                <option value="graded">Beri nilai</option>
                <option value="returned">Kembalikan untuk revisi</option>
              </select>
            </div>
            <div>
              <label htmlFor={`sc-${s.id}`}>Nilai (0–{s.maxScore})</label>
              <input id={`sc-${s.id}`} name="score" type="number" min={0} max={s.maxScore} step="0.5" defaultValue={s.score ?? ""} />
            </div>
          </div>
          <div>
            <label htmlFor={`fb-${s.id}`}>Umpan balik</label>
            <textarea id={`fb-${s.id}`} name="feedback" rows={3} defaultValue={s.feedback ?? ""} />
          </div>
          <FormMessage ok={state.ok} message={state.message} />
          <div style={{ display: "flex", gap: 10 }}>
            <button className="kb1" type="submit" disabled={pending} style={{ width: "auto" }}>
              {pending ? "Menyimpan…" : "Simpan penilaian"}
            </button>
            {s.status !== "submitted" && (
              <button className="kb2" type="button" style={{ width: "auto" }} onClick={() => setOpen(false)}>
                Tutup
              </button>
            )}
          </div>
        </form>
      ) : (
        <button type="button" className="lk" onClick={() => setOpen(true)}>
          Ubah penilaian
        </button>
      )}
    </article>
  );
}
