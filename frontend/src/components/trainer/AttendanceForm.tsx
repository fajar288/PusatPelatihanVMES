"use client";

import { useActionState, useState } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { type FormState } from "@/lib/actions/auth";
import { markAttendanceAction } from "@/lib/actions/trainer";
import type { TrainerClass } from "@/lib/trainer";

const OPTIONS = [
  ["present", "Hadir"],
  ["late", "Terlambat"],
  ["excused", "Izin"],
  ["absent", "Tidak hadir"],
] as const;

/** Matriks kehadiran: pilih sesi, tandai setiap peserta, simpan massal. */
export default function AttendanceForm({ cls }: { cls: TrainerClass }) {
  const [state, action, pending] = useActionState<FormState, FormData>(markAttendanceAction, { ok: false });
  const [sessionNo, setSessionNo] = useState<number>(cls.sessions[0]?.no ?? 1);
  const participants = cls.participants ?? [];
  const session = cls.sessions.find((s) => s.no === sessionNo);

  if (!cls.sessions.length) return <p className="fh">Belum ada sesi untuk kelas ini. Minta admin menambahkan sesi di panel admin.</p>;
  if (!participants.length) return <p className="fh">Belum ada peserta terdaftar di kelas ini.</p>;

  return (
    <form className="ff" action={action}>
      <input type="hidden" name="code" value={cls.code} />
      <input type="hidden" name="session_no" value={sessionNo} />
      <div className="fg2">
        <div>
          <label htmlFor="att-session">Sesi</label>
          <select id="att-session" value={sessionNo} onChange={(e) => setSessionNo(Number(e.target.value))}>
            {cls.sessions.map((s) => (
              <option key={s.no} value={s.no}>
                Sesi {s.no} — {s.dateLabel}
                {s.topic ? ` · ${s.topic}` : ""} ({s.marked ?? 0}/{participants.length} dicatat)
              </option>
            ))}
          </select>
        </div>
        <div>
          <label>Tandai semua</label>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            {OPTIONS.map(([v, l]) => (
              <button
                key={v}
                type="button"
                className="pm"
                style={{ width: "auto", padding: "8px 12px" }}
                onClick={() => document.querySelectorAll<HTMLSelectElement>("select[name^='att_']").forEach((el) => (el.value = v))}
              >
                {l}
              </button>
            ))}
          </div>
        </div>
      </div>
      <table className="att">
        <thead>
          <tr>
            <th>Peserta</th>
            <th>Kehadiran ({session ? `sesi ${session.no}` : ""})</th>
            <th>Catatan</th>
            <th>Total %</th>
          </tr>
        </thead>
        <tbody>
          {participants.map((p) => (
            <tr key={p.id}>
              <td>
                <b>{p.name}</b>
                <small>{p.email ?? ""}</small>
              </td>
              <td>
                <select name={`att_${p.id}`} defaultValue={p.attendance[String(sessionNo)] ?? ""} key={`${p.id}-${sessionNo}`}>
                  <option value="">— belum —</option>
                  {OPTIONS.map(([v, l]) => (
                    <option key={v} value={v}>
                      {l}
                    </option>
                  ))}
                </select>
              </td>
              <td>
                <input name={`note_${p.id}`} placeholder="opsional" />
              </td>
              <td>{p.attendancePercent === null ? "-" : `${p.attendancePercent}%`}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <FormMessage ok={state.ok} message={state.message} />
      <button className="kb1" type="submit" disabled={pending}>
        {pending ? "Menyimpan…" : "Simpan Kehadiran"}
      </button>
    </form>
  );
}
