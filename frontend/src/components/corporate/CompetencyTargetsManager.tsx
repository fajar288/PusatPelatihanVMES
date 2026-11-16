"use client";

import { useActionState, useState, useTransition } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { type CorpState, deleteCompetencyTargetAction, saveCompetencyTargetAction } from "@/lib/actions/corporate";
import type { CorporateCompetencies } from "@/lib/corporate";

/**
 * Target kompetensi per posisi (§20.16): daftar + tambah/ubah + hapus. Hanya tampil untuk peran yang boleh mengatur
 * (Corporate Owner / HR tanpa batas unit); server memeriksa ulang setiap perubahan dan mencatatnya di audit log.
 */
export default function CompetencyTargetsManager({ org, data }: { org: string; data: Pick<CorporateCompetencies, "targets" | "catalog" | "positions" | "canManage"> }) {
  const [state, action, saving] = useActionState<CorpState, FormData>(saveCompetencyTargetAction, { ok: false });
  const [msg, setMsg] = useState<CorpState | null>(null);
  const [pending, start] = useTransition();

  return (
    <>
      {data.targets.length === 0 ? (
        <p className="fh">Belum ada target. Tetapkan level kompetensi yang dibutuhkan untuk setiap posisi agar gap karyawan bisa dihitung.</p>
      ) : (
        <ul className="sp">
          {data.targets.map((t) => (
            <li key={t.id}>
              <span className="spl">
                <b>{t.position}</b>
                <small>
                  {t.competencyName} ({t.competency})
                </small>
              </span>
              <b className="spv">
                <span className="flex flex-wrap items-center justify-end gap-2">
                  <span>Level {t.requiredLevel}</span>
                  {data.canManage && (
                    <button
                      type="button"
                      className="copyb"
                      disabled={pending}
                      onClick={() => {
                        if (window.confirm(`Hapus target ${t.competency} untuk ${t.position}?`)) start(async () => setMsg(await deleteCompetencyTargetAction(org, t.id)));
                      }}
                    >
                      Hapus
                    </button>
                  )}
                </span>
              </b>
            </li>
          ))}
        </ul>
      )}
      {msg && <FormMessage ok={msg.ok} message={msg.message} />}

      {data.canManage && (
        <>
          <h3 style={{ marginTop: 22, fontSize: 17, color: "var(--ink)" }}>Tambah / ubah target</h3>
          {data.catalog.length === 0 ? (
            <p className="fh">Master kompetensi belum diisi oleh VMES.</p>
          ) : (
            <form className="ff" action={action} style={{ marginTop: 10 }} key={state.ok ? state.message : undefined}>
              <input type="hidden" name="org" value={org} />
              <div className="fg2">
                <div>
                  <label htmlFor="ct-position">Posisi</label>
                  <input id="ct-position" name="position" required maxLength={150} list="ct-positions" placeholder="Teknisi Maintenance" />
                  <datalist id="ct-positions">
                    {data.positions.map((p) => (
                      <option key={p} value={p} />
                    ))}
                  </datalist>
                </div>
                <div>
                  <label htmlFor="ct-competency">Kompetensi</label>
                  <select id="ct-competency" name="competency" required defaultValue="">
                    <option value="" disabled>
                      Pilih kompetensi
                    </option>
                    {data.catalog.map((c) => (
                      <option key={c.code} value={c.code}>
                        {c.name} ({c.code})
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label htmlFor="ct-level">Level minimal (1-100)</label>
                  <input id="ct-level" name="required_level" type="number" min={1} max={100} required defaultValue={75} />
                </div>
              </div>
              <button className="kb2" type="submit" disabled={saving} style={{ marginTop: 12 }}>
                {saving ? "Menyimpan…" : "Simpan target"}
              </button>
              {(state.message || state.errors) && <FormMessage ok={state.ok} message={state.message} />}
            </form>
          )}
        </>
      )}
    </>
  );
}
