"use client";

import { useActionState, useState, useTransition } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { addCorporateUserAction, type CorpState, disableCorporateUserAction, updateCorporateUserAction } from "@/lib/actions/corporate";
import { type CorporateUserRow, ROLE_OPTIONS, type Unit } from "@/lib/corporate";

/** Daftar + tambah pengguna perusahaan, ubah peran/batas unit, cabut akses (server memeriksa: owner terakhir, diri sendiri). */
export default function CorporateUsersManager({ org, users, units }: { org: string; users: CorporateUserRow[]; units: Unit[] }) {
  const [add, addAction, adding] = useActionState<CorpState, FormData>(addCorporateUserAction, { ok: false });
  const [msg, setMsg] = useState<CorpState | null>(null);
  const [pending, start] = useTransition();

  return (
    <>
      <ul className="sp" style={{ marginTop: 12 }}>
        {users.map((u) => (
          <li key={u.id}>
            <span className="spl">
              <b>
                {u.name}
                {u.isMe ? " (Anda)" : ""}
              </b>
              <small>
                {u.email}
                {u.unit ? ` · ${u.unit}` : ""}
              </small>
            </span>
            <b className="spv">
              {u.status !== "active" ? (
                <span className="jst no">Dicabut</span>
              ) : u.isMe ? (
                <span className="jst ok">{u.roleLabel}</span>
              ) : (
                <span className="flex flex-wrap items-center justify-end gap-2">
                  <select
                    aria-label={`Peran ${u.name}`}
                    defaultValue={u.role}
                    disabled={pending}
                    className="rounded-full border border-[var(--ln)] bg-white px-3 py-1 text-[13px] font-bold"
                    onChange={(e) => start(async () => setMsg(await updateCorporateUserAction(org, u.id, e.target.value, u.unitId)))}
                  >
                    {ROLE_OPTIONS.map((r) => (
                      <option key={r.value} value={r.value}>
                        {r.label}
                      </option>
                    ))}
                  </select>
                  <button
                    type="button"
                    className="copyb"
                    disabled={pending}
                    onClick={() => {
                      if (window.confirm(`Cabut akses ${u.name}?`)) start(async () => setMsg(await disableCorporateUserAction(org, u.id)));
                    }}
                  >
                    Cabut akses
                  </button>
                </span>
              )}
            </b>
          </li>
        ))}
      </ul>
      {msg && <FormMessage ok={msg.ok} message={msg.message} />}

      <h3 style={{ marginTop: 22, fontSize: 17, color: "var(--ink)" }}>Tambah pengguna</h3>
      <form className="ff" action={addAction} style={{ marginTop: 10 }} key={add.ok ? add.message : undefined}>
        <input type="hidden" name="org" value={org} />
        <div className="fg2">
          <div>
            <label htmlFor="cu-email">Email akun</label>
            <input id="cu-email" name="email" type="email" required placeholder="nama@perusahaan.co.id" />
          </div>
          <div>
            <label htmlFor="cu-role">Peran</label>
            <select id="cu-role" name="role" defaultValue="hr_admin">
              {ROLE_OPTIONS.map((r) => (
                <option key={r.value} value={r.value}>
                  {r.label} — {r.hint}
                </option>
              ))}
            </select>
          </div>
        </div>
        {units.length > 0 && (
          <div>
            <label htmlFor="cu-unit">
              Batas unit <small>(opsional, mis. untuk supervisor)</small>
            </label>
            <select id="cu-unit" name="unit_id" defaultValue="">
              <option value="">Seluruh organisasi</option>
              {units.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.label}
                </option>
              ))}
            </select>
          </div>
        )}
        <FormMessage ok={add.ok} message={add.message} />
        <button className="kb1" type="submit" disabled={adding}>
          {adding ? "Menambahkan…" : "Tambah pengguna"}
        </button>
      </form>
    </>
  );
}
