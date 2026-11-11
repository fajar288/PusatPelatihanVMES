"use client";

import { useActionState } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { type FormState } from "@/lib/actions/auth";
import { saveLogbookAction } from "@/lib/actions/monitoring";
import type { LogbookInfo, LogbooksData } from "@/lib/monitoring";

/** Form logbook per pertemuan (buat/ubah): data pertemuan, isi logbook, catatan per peserta, dokumentasi. */
export default function LogbookForm({ d, logbook, onDone }: { d: LogbooksData; logbook?: LogbookInfo | null; onDone?: () => void }) {
  const [state, action, pending] = useActionState<FormState, FormData>(saveLogbookAction, { ok: false });
  const entries = new Map((logbook?.entries ?? []).map((e) => [e.enrollmentId, e]));
  const freeSessions = d.sessions.filter((s) => !s.hasLogbook || s.id === logbook?.sessionId);
  const defaultSession = logbook?.sessionId ?? freeSessions[0]?.id ?? "";
  const defaultDate = logbook?.date ?? d.sessions.find((s) => s.id === defaultSession)?.date ?? new Date().toISOString().slice(0, 10);

  return (
    <form className="ff" action={action} style={{ marginTop: 14 }}>
      <input type="hidden" name="code" value={d.class.code} />
      <input type="hidden" name="id" value={logbook?.id ?? ""} />
      <div className="fg2">
        <div>
          <label htmlFor="lb-session">
            Sesi / pertemuan <small>(opsional)</small>
          </label>
          <select id="lb-session" name="session_id" defaultValue={defaultSession} disabled={logbook?.isLocked}>
            <option value="">— tanpa sesi —</option>
            {d.sessions.map((s) => (
              <option key={s.id} value={s.id} disabled={s.hasLogbook && s.id !== logbook?.sessionId}>
                Sesi {s.no} — {s.dateLabel}
                {s.topic ? ` · ${s.topic}` : ""}
                {s.hasLogbook && s.id !== logbook?.sessionId ? " (sudah ada logbook)" : ""}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="lb-date">Tanggal</label>
          <input id="lb-date" name="date" type="date" required defaultValue={defaultDate} />
        </div>
      </div>
      <div>
        <label htmlFor="lb-topic">Topik</label>
        <input id="lb-topic" name="topic" required maxLength={255} defaultValue={logbook?.topic ?? d.sessions.find((s) => s.id === defaultSession)?.topic ?? ""} placeholder="mis. Subnetting & VLAN dasar" />
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="lb-act">Aktivitas pembelajaran</label>
          <textarea id="lb-act" name="activities" rows={3} defaultValue={logbook?.activities ?? ""} placeholder="Ceramah, demo, diskusi, latihan…" />
        </div>
        <div>
          <label htmlFor="lb-comp">
            Kompetensi yang dilatih <small>(pisahkan dengan koma / baris baru)</small>
          </label>
          <textarea id="lb-comp" name="competencies" rows={3} defaultValue={(logbook?.competencies ?? []).join("\n")} placeholder={"Konfigurasi VLAN\nTroubleshooting konektivitas"} />
        </div>
        <div>
          <label htmlFor="lb-prac">Praktik yang dilakukan</label>
          <textarea id="lb-prac" name="practice" rows={3} defaultValue={logbook?.practice ?? ""} />
        </div>
        <div>
          <label htmlFor="lb-obs">Observasi trainer</label>
          <textarea id="lb-obs" name="observations" rows={3} defaultValue={logbook?.observations ?? ""} />
        </div>
        <div>
          <label htmlFor="lb-obst">Kendala</label>
          <textarea id="lb-obst" name="obstacles" rows={2} defaultValue={logbook?.obstacles ?? ""} />
        </div>
        <div>
          <label htmlFor="lb-notes">Catatan trainer</label>
          <textarea id="lb-notes" name="notes" rows={2} defaultValue={logbook?.notes ?? ""} />
        </div>
      </div>

      <div>
        <label>
          Catatan per peserta <small>(status praktik &amp; observasi individual — tampil di progres peserta setelah logbook divalidasi)</small>
        </label>
        {d.participants.length ? (
          <table className="att">
            <thead>
              <tr>
                <th>Peserta</th>
                <th>Praktik</th>
                <th>Observasi</th>
              </tr>
            </thead>
            <tbody>
              {d.participants.map((p) => {
                const e = entries.get(p.id);
                return (
                  <tr key={p.id}>
                    <td>
                      <b>{p.name}</b>
                      <small>{p.email ?? ""}</small>
                    </td>
                    <td>
                      <select name={`ps_${p.id}`} defaultValue={e?.practiceStatus ?? ""}>
                        <option value="">—</option>
                        {(Object.keys(d.practiceLabels) as (keyof typeof d.practiceLabels)[]).map((k) => (
                          <option key={k} value={k}>
                            {d.practiceLabels[k]}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td>
                      <input name={`ob_${p.id}`} defaultValue={e?.observation ?? ""} placeholder="opsional" maxLength={2000} />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        ) : (
          <p className="fh">Belum ada peserta di kelas ini.</p>
        )}
      </div>

      <div>
        <label htmlFor="lb-docs">
          Dokumentasi <small>(foto/PDF/video, maks. 10 MB per berkas)</small>
        </label>
        {logbook?.documentation.length ? (
          <ul className="sp" style={{ margin: "4px 0 10px" }}>
            {logbook.documentation.map((doc) => (
              <li key={doc.index}>
                <span>
                  <a className="lk" style={{ marginTop: 0, fontSize: 14 }} href={`/trainer/logbook/berkas/${logbook.id}/${doc.index}`}>
                    {doc.name}
                  </a>
                </span>
                <b>
                  <label className="fc" style={{ fontWeight: 600 }}>
                    <input type="checkbox" name="remove_docs" value={doc.index} /> hapus
                  </label>
                </b>
              </li>
            ))}
          </ul>
        ) : null}
        <input id="lb-docs" name="docs" type="file" multiple accept="image/*,.pdf,.mp4,.doc,.docx,.xls,.xlsx,.ppt,.pptx" />
      </div>

      <FormMessage ok={state.ok} message={state.message} />
      <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
        <button className="kb1" type="submit" disabled={pending} style={{ width: "auto" }}>
          {pending ? "Menyimpan…" : logbook ? "Simpan perubahan" : "Simpan logbook"}
        </button>
        {onDone && (
          <button className="kb2" type="button" style={{ width: "auto" }} onClick={onDone}>
            Tutup
          </button>
        )}
      </div>
    </form>
  );
}
