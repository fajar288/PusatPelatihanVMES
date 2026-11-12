"use client";

import { useActionState } from "react";
import { FieldError, FormMessage } from "@/components/auth/AuthShell";
import { createRequestAction, type CorpState } from "@/lib/actions/corporate";
import type { Unit } from "@/lib/corporate";

const DELIVERY = [
  { value: "onsite", label: "On-site (di lokasi perusahaan)" },
  { value: "vmes", label: "Di VMES Training Center" },
  { value: "online", label: "Online" },
  { value: "hybrid", label: "Hybrid" },
];

/** Form Corporate Training Request (§20.3): simpan sebagai draf atau langsung kirim ke VMES. */
export default function NewRequestForm({ org, packages, units }: { org: string; packages: { slug: string; title: string; category: string | null }[]; units: Unit[] }) {
  const [state, action, pending] = useActionState<CorpState, FormData>(createRequestAction, { ok: false });
  return (
    <form className="ff" action={action} style={{ marginTop: 16 }}>
      <input type="hidden" name="org" value={org} />
      <div>
        <label htmlFor="rq-package">Program training</label>
        <select id="rq-package" name="package" required defaultValue="">
          <option value="" disabled>
            Pilih program…
          </option>
          {packages.map((p) => (
            <option key={p.slug} value={p.slug}>
              {p.title}
              {p.category ? ` — ${p.category}` : ""}
            </option>
          ))}
        </select>
        <FieldError errors={state.errors} name="package_id" />
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="rq-seats">Jumlah kursi</label>
          <input id="rq-seats" name="seats" type="number" min={1} max={500} defaultValue={10} required />
          <FieldError errors={state.errors} name="seats" />
        </div>
        <div>
          <label htmlFor="rq-delivery">Metode delivery</label>
          <select id="rq-delivery" name="delivery" defaultValue="onsite">
            {DELIVERY.map((d) => (
              <option key={d.value} value={d.value}>
                {d.label}
              </option>
            ))}
          </select>
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="rq-date">
            Target tanggal <small>(opsional)</small>
          </label>
          <input id="rq-date" name="target_date" type="date" />
          <FieldError errors={state.errors} name="target_date" />
        </div>
        <div>
          <label htmlFor="rq-loc">
            Lokasi <small>(opsional)</small>
          </label>
          <input id="rq-loc" name="location" placeholder="mis. Pabrik Batam, Gedung B" />
        </div>
      </div>
      {units.length > 0 && (
        <div>
          <label htmlFor="rq-unit">
            Departemen / cost center <small>(opsional)</small>
          </label>
          <select id="rq-unit" name="unit_id" defaultValue="">
            <option value="">Seluruh organisasi</option>
            {units.map((u) => (
              <option key={u.id} value={u.id}>
                {u.label}
              </option>
            ))}
          </select>
          <FieldError errors={state.errors} name="unit_id" />
        </div>
      )}
      <div>
        <label htmlFor="rq-trainer">
          Kebutuhan trainer <small>(opsional)</small>
        </label>
        <textarea id="rq-trainer" name="trainer_requirements" rows={2} placeholder="mis. berpengalaman PLC Siemens S7-1200" />
      </div>
      <div>
        <label htmlFor="rq-notes">
          Catatan <small>(opsional)</small>
        </label>
        <textarea id="rq-notes" name="notes" rows={3} placeholder="Tujuan, level peserta, kebutuhan alat/ruangan, dll." />
      </div>
      <FormMessage ok={state.ok} message={state.message} />
      <div className="flex flex-wrap gap-3">
        <button className="kb1" type="submit" name="intent" value="submit" disabled={pending}>
          {pending ? "Menyimpan…" : "Kirim ke VMES"}
        </button>
        <button className="kb2" type="submit" name="intent" value="draft" disabled={pending}>
          Simpan sebagai draf
        </button>
      </div>
    </form>
  );
}
