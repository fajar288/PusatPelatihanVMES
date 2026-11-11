"use client";

import { useState, useTransition } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { validateLogbookAction } from "@/lib/actions/monitoring";
import { type LogbookInfo, type LogbooksData, PRACTICE_PILL } from "@/lib/monitoring";
import LogbookForm from "./LogbookForm";

/** Satu logbook: ringkasan isi, catatan peserta, dokumentasi, aksi Ubah (bila belum dikunci) & Validasi. */
export default function LogbookCard({ d, l }: { d: LogbooksData; l: LogbookInfo }) {
  const [edit, setEdit] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [pending, start] = useTransition();
  const names = new Map(d.participants.map((p) => [p.id, p.name]));

  const validate = () => {
    if (!confirm("Validasi logbook ini? Setelah divalidasi, logbook dikunci dan hanya admin yang bisa membuka kunci.")) return;
    start(async () => {
      const r = await validateLogbookAction(l.id, d.class.code);
      setMsg(r.ok ? { ok: true, text: "Logbook divalidasi dan dikunci." } : { ok: false, text: r.message });
    });
  };

  return (
    <article className="kbx lsub">
      <div className="pcard-h">
        <h3 style={{ fontSize: 17 }}>
          {l.dateLabel}
          {l.sessionNo ? <small className="fh"> • Sesi {l.sessionNo}</small> : null} — {l.topic}
        </h3>
        <span className={"jst " + (l.isLocked ? "ok" : "wn")}>
          {l.statusLabel}
          {l.isLocked ? " 🔒" : ""}
        </span>
      </div>
      <p className="fh" style={{ marginTop: 4 }}>
        {l.trainer ? `${l.trainer} • ` : ""}
        {l.isLocked && l.validatedAt ? `divalidasi ${new Date(l.validatedAt).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" })}${l.validatedBy ? ` oleh ${l.validatedBy}` : ""}` : "draf — belum divalidasi"}
      </p>
      {edit ? (
        <LogbookForm d={d} logbook={l} onDone={() => setEdit(false)} />
      ) : (
        <>
          <dl className="lbd">
            {l.competencies.length > 0 && (
              <>
                <dt>Kompetensi</dt>
                <dd>
                  {l.competencies.map((c) => (
                    <span className="chip" key={c}>
                      {c}
                    </span>
                  ))}
                </dd>
              </>
            )}
            {l.activities && (
              <>
                <dt>Aktivitas</dt>
                <dd>{l.activities}</dd>
              </>
            )}
            {l.practice && (
              <>
                <dt>Praktik</dt>
                <dd>{l.practice}</dd>
              </>
            )}
            {l.observations && (
              <>
                <dt>Observasi</dt>
                <dd>{l.observations}</dd>
              </>
            )}
            {l.obstacles && (
              <>
                <dt>Kendala</dt>
                <dd>{l.obstacles}</dd>
              </>
            )}
            {l.notes && (
              <>
                <dt>Catatan</dt>
                <dd>{l.notes}</dd>
              </>
            )}
            {l.documentation.length > 0 && (
              <>
                <dt>Dokumentasi</dt>
                <dd>
                  {l.documentation.map((doc) => (
                    <a key={doc.index} className="lk" style={{ marginTop: 0, marginRight: 12, fontSize: 14 }} href={`/trainer/logbook/berkas/${l.id}/${doc.index}`}>
                      {doc.name}
                    </a>
                  ))}
                </dd>
              </>
            )}
          </dl>
          {l.entries.length > 0 && (
            <table className="att" style={{ marginTop: 10 }}>
              <thead>
                <tr>
                  <th>Peserta</th>
                  <th>Praktik</th>
                  <th>Observasi</th>
                </tr>
              </thead>
              <tbody>
                {l.entries.map((e) => (
                  <tr key={e.enrollmentId}>
                    <td>
                      <b>{names.get(e.enrollmentId) ?? `#${e.enrollmentId}`}</b>
                    </td>
                    <td>{e.practiceStatus ? <span className={"jst " + PRACTICE_PILL[e.practiceStatus]}>{e.practiceLabel}</span> : <span className="fh">-</span>}</td>
                    <td>{e.observation ?? <span className="fh">-</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <FormMessage ok={msg?.ok} message={msg?.text} />
          <div style={{ display: "flex", gap: 10, flexWrap: "wrap", marginTop: 12 }}>
            {!l.isLocked && (
              <>
                <button type="button" className="kb2" style={{ width: "auto" }} onClick={() => setEdit(true)}>
                  Ubah
                </button>
                <button type="button" className="kb1" style={{ width: "auto" }} disabled={pending} onClick={validate}>
                  {pending ? "Memvalidasi…" : "Validasi & kunci"}
                </button>
              </>
            )}
            {l.isLocked && <span className="fh">Terkunci. Perubahan hanya oleh admin (tercatat di audit log).</span>}
          </div>
        </>
      )}
    </article>
  );
}
