"use client";

import { useActionState, useState } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { type FormState } from "@/lib/actions/auth";
import { saveAssessmentAction } from "@/lib/actions/monitoring";
import type { AssessmentsData } from "@/lib/monitoring";

type Item = AssessmentsData["participants"][number];

/** Penilaian kompetensi satu peserta: skor per kriteria rubrik (dengan deskriptor level), catatan, simpan draf / validasi & kunci. */
export default function AssessmentForm({ code, item }: { code: string; item: Item }) {
  const [state, action, pending] = useActionState<FormState, FormData>(saveAssessmentAction, { ok: false });
  const a = item.assessment;
  const [open, setOpen] = useState(a.status === "draft");
  const [scores, setScores] = useState<Record<number, string>>(Object.fromEntries(a.criteria.map((c) => [c.id, c.score === null ? "" : String(c.score)])));
  const live = (() => {
    let w = 0;
    let s = 0;
    for (const c of a.criteria) {
      const v = scores[c.id];
      if (v === "" || v === undefined || c.maxScore === 0) continue;
      w += c.weight;
      s += Math.min(1, Number(v) / c.maxScore) * c.weight;
    }
    return w > 0 ? Math.round((s / w) * 10000) / 100 : null;
  })();

  return (
    <article className="kbx lsub">
      <div className="pcard-h">
        <h3 style={{ fontSize: 17 }}>
          {item.participant.name} <small className="fh">• {item.participant.email ?? ""}</small>
        </h3>
        <span className={"jst " + (a.status === "validated" ? (a.isCompetent ? "ok" : "no") : a.percent !== null ? "wn" : "no")}>
          {a.statusLabel}
          {a.percent !== null ? ` • ${a.percent}%` : ""}
          {a.status === "validated" ? (a.isCompetent ? " • Kompeten" : " • Belum kompeten") : ""}
          {a.isLocked ? " 🔒" : ""}
        </span>
      </div>
      <p className="fh" style={{ marginTop: 4 }}>
        {a.assessedLabel ? `dinilai ${a.assessedLabel}${a.assessedBy ? ` oleh ${a.assessedBy}` : ""}` : "belum dinilai"}
        {a.validatedBy ? ` • divalidasi oleh ${a.validatedBy}` : ""}
      </p>
      {!open ? (
        <>
          <ul className="sp" style={{ marginBottom: 0 }}>
            {a.criteria.map((c) => (
              <li key={c.id}>
                <span>
                  {c.name}
                  {c.note ? <small className="fh"> — {c.note}</small> : null}
                </span>
                <b>
                  {c.score === null ? "-" : `${c.score}/${c.maxScore}`}
                  {c.levelLabel ? <small className="fh"> • {c.levelLabel}</small> : null}
                </b>
              </li>
            ))}
          </ul>
          {a.summary && (
            <p className="fh" style={{ marginTop: 10 }}>
              Ringkasan: {a.summary}
            </p>
          )}
          {a.recommendation && (
            <p className="fh" style={{ marginTop: 4 }}>
              Rekomendasi: {a.recommendation}
            </p>
          )}
          {!a.isLocked ? (
            <button type="button" className="lk" onClick={() => setOpen(true)}>
              Ubah penilaian
            </button>
          ) : (
            <p className="fh" style={{ marginTop: 10 }}>
              Terkunci. Perubahan hanya oleh admin (tercatat di audit log).
            </p>
          )}
        </>
      ) : (
        <form className="ff" action={action} style={{ marginTop: 14 }}>
          <input type="hidden" name="code" value={code} />
          <input type="hidden" name="enrollment" value={item.participant.id} />
          <table className="att asm">
            <thead>
              <tr>
                <th>Kriteria</th>
                <th>Skor</th>
                <th>Catatan</th>
              </tr>
            </thead>
            <tbody>
              {a.criteria.map((c) => {
                const v = scores[c.id] ?? "";
                const lvl = c.levels.find((l) => String(l.score) === v);
                return (
                  <tr key={c.id}>
                    <td>
                      <b>{c.name}</b>
                      <small>
                        {c.competency ? `${c.competency} • ` : ""}
                        {c.description ?? ""}
                        {c.weight !== 1 ? ` • bobot ${c.weight}` : ""}
                      </small>
                    </td>
                    <td>
                      {c.levels.length ? (
                        <select name={`sc_${c.id}`} value={v} onChange={(e) => setScores({ ...scores, [c.id]: e.target.value })}>
                          <option value="">—</option>
                          {c.levels.map((l) => (
                            <option key={l.score} value={l.score}>
                              {l.score} — {l.label}
                            </option>
                          ))}
                        </select>
                      ) : (
                        <input name={`sc_${c.id}`} type="number" min={0} max={c.maxScore} step="0.5" value={v} onChange={(e) => setScores({ ...scores, [c.id]: e.target.value })} placeholder={`0–${c.maxScore}`} />
                      )}
                      {lvl?.description ? <small>{lvl.description}</small> : <small>maks. {c.maxScore}</small>}
                    </td>
                    <td>
                      <input name={`nt_${c.id}`} defaultValue={c.note ?? ""} placeholder="opsional" maxLength={500} />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          <p className="fh">
            Perkiraan total: <b>{live === null ? "-" : `${live}%`}</b> (ambang kompeten {a.threshold}%)
          </p>
          <div className="fg2">
            <div>
              <label htmlFor={`sum-${a.id}`}>Ringkasan / umpan balik</label>
              <textarea id={`sum-${a.id}`} name="summary" rows={3} defaultValue={a.summary ?? ""} />
            </div>
            <div>
              <label htmlFor={`rec-${a.id}`}>Rekomendasi</label>
              <textarea id={`rec-${a.id}`} name="recommendation" rows={3} defaultValue={a.recommendation ?? ""} />
            </div>
          </div>
          <FormMessage ok={state.ok} message={state.message} />
          <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
            <button className="kb2" type="submit" name="validate" value="0" disabled={pending} style={{ width: "auto" }}>
              {pending ? "Menyimpan…" : "Simpan draf"}
            </button>
            <button
              className="kb1"
              type="submit"
              name="validate"
              value="1"
              disabled={pending}
              style={{ width: "auto" }}
              onClick={(e) => {
                if (!confirm("Validasi & kunci penilaian ini? Hasil akan tampil ke peserta dan hanya admin yang bisa membuka kunci.")) e.preventDefault();
              }}
            >
              Validasi & kunci
            </button>
            {a.percent !== null && (
              <button className="kb2" type="button" style={{ width: "auto" }} onClick={() => setOpen(false)}>
                Tutup
              </button>
            )}
          </div>
        </form>
      )}
    </article>
  );
}
