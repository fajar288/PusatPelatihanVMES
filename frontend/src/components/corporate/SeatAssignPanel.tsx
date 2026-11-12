"use client";

import { useActionState, useState } from "react";
import { FieldError, FormMessage } from "@/components/auth/AuthShell";
import { assignEmployeeAction, assignSeatAction, type CorpState } from "@/lib/actions/corporate";
import type { Employee, Unit } from "@/lib/corporate";

type Tab = "manual" | "employee";

/**
 * Isi kursi (§4.3, §20.4): data peserta manual (dicocokkan ke akun yang ada; bila tidak pasti → undangan email)
 * atau pilih dari karyawan aktif yang sudah tersambung ke akun VMES.
 */
export default function SeatAssignPanel({ org, code, freeSeats, employees, units }: { org: string; code: string; freeSeats: { id: number; no: number }[]; employees: Employee[]; units: Unit[] }) {
  const [tab, setTab] = useState<Tab>("manual");
  const [manual, manualAction, manualPending] = useActionState<CorpState, FormData>(assignSeatAction, { ok: false });
  const [emp, empAction, empPending] = useActionState<CorpState, FormData>(assignEmployeeAction, { ok: false });

  const seatSelect = (id: string) => (
    <div>
      <label htmlFor={id}>Kursi</label>
      <select id={id} name="seat" required defaultValue={freeSeats[0]?.id}>
        {freeSeats.map((s) => (
          <option key={s.id} value={s.id}>
            Kursi {String(s.no).padStart(2, "0")}
          </option>
        ))}
      </select>
    </div>
  );

  return (
    <div>
      <div className="flex flex-wrap gap-2" role="tablist" aria-label="Cara mengisi peserta">
        {(
          [
            ["manual", "Isi data peserta"],
            ["employee", `Pilih karyawan (${employees.length})`],
          ] as [Tab, string][]
        ).map(([t, label]) => (
          <button key={t} type="button" role="tab" aria-selected={tab === t} className={`copyb ${tab === t ? "bg-[var(--p)]! text-white! border-[var(--p)]!" : ""}`} onClick={() => setTab(t)}>
            {label}
          </button>
        ))}
      </div>

      {tab === "manual" ? (
        <form className="ff" action={manualAction} style={{ marginTop: 16 }} key={manual.message}>
          <input type="hidden" name="org" value={org} />
          <input type="hidden" name="code" value={code} />
          <div className="fg2">
            {seatSelect("as-seat")}
            <div>
              <label htmlFor="as-name">Nama lengkap</label>
              <input id="as-name" name="name" required autoComplete="off" />
              <FieldError errors={manual.errors} name="name" />
            </div>
          </div>
          <div className="fg2">
            <div>
              <label htmlFor="as-email">Email</label>
              <input id="as-email" name="email" type="email" autoComplete="off" placeholder="untuk undangan bila belum punya akun" />
              <FieldError errors={manual.errors} name="email" />
            </div>
            <div>
              <label htmlFor="as-phone">
                No. HP <small>(opsional)</small>
              </label>
              <input id="as-phone" name="phone" autoComplete="off" />
            </div>
          </div>
          <div className="fg2">
            <div>
              <label htmlFor="as-emp">
                Employee ID <small>(opsional)</small>
              </label>
              <input id="as-emp" name="employee_id" autoComplete="off" />
            </div>
            <div>
              <label htmlFor="as-pos">
                Jabatan <small>(opsional)</small>
              </label>
              <input id="as-pos" name="position" autoComplete="off" />
            </div>
          </div>
          <div className="fg2">
            {units.length > 0 ? (
              <div>
                <label htmlFor="as-unit">
                  Departemen <small>(opsional)</small>
                </label>
                <select id="as-unit" name="unit_id" defaultValue="">
                  <option value="">Sesuai permintaan</option>
                  {units.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.label}
                    </option>
                  ))}
                </select>
              </div>
            ) : (
              <div />
            )}
            <div>
              <label htmlFor="as-mbr">
                Nomor member VMES <small>(bila diketahui)</small>
              </label>
              <input id="as-mbr" name="member_number" placeholder="MBR-2026-000123" autoComplete="off" />
              <FieldError errors={manual.errors} name="member_number" />
            </div>
          </div>
          <FormMessage ok={manual.ok} message={manual.message} />
          <button className="kb1" type="submit" disabled={manualPending}>
            {manualPending ? "Memproses…" : "Tetapkan / kirim undangan"}
          </button>
        </form>
      ) : employees.length === 0 ? (
        <p className="fh" style={{ marginTop: 16 }}>
          Belum ada karyawan yang tersambung ke akun VMES. Isi data peserta secara manual — setelah mereka menerima undangan, mereka akan muncul di daftar ini.
        </p>
      ) : (
        <form className="ff" action={empAction} style={{ marginTop: 16 }}>
          <input type="hidden" name="org" value={org} />
          <input type="hidden" name="code" value={code} />
          <div className="fg2">
            {seatSelect("ae-seat")}
            <div>
              <label htmlFor="ae-emp">Karyawan</label>
              <select id="ae-emp" name="employment_id" required defaultValue="">
                <option value="" disabled>
                  Pilih karyawan…
                </option>
                {employees.map((e) => (
                  <option key={e.id} value={e.id}>
                    {e.name}
                    {e.employeeId ? ` (${e.employeeId})` : ""}
                    {e.unit ? ` — ${e.unit}` : ""}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <FormMessage ok={emp.ok} message={emp.message} />
          <button className="kb1" type="submit" disabled={empPending}>
            {empPending ? "Memproses…" : "Tetapkan ke kursi"}
          </button>
        </form>
      )}
    </div>
  );
}
