"use client";

import { useActionState } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { type CorpState, importParticipantsAction } from "@/lib/actions/corporate";

const LABEL: Record<string, { text: string; pill: "ok" | "wn" | "no" }> = {
  assigned: { text: "Ditetapkan", pill: "ok" },
  invited: { text: "Diundang", pill: "wn" },
  review: { text: "Diperiksa VMES", pill: "wn" },
  error: { text: "Gagal", pill: "no" },
};

/** Unggah massal CSV/XLSX (§20.4): validasi per baris + laporan galat; tiap baris lewat identity matching yang sama. */
export default function BulkImportForm({ org, code, templateHref }: { org: string; code: string; templateHref: string }) {
  const [state, action, pending] = useActionState<CorpState, FormData>(importParticipantsAction, { ok: false });
  return (
    <div>
      <p className="fh">
        Unduh <a href={templateHref}>template CSV</a> (kolom: nama, email, hp, employee_id, nomor_member, departemen, jabatan), isi satu peserta per baris, lalu unggah. Bisa juga file Excel (.xlsx) dengan judul kolom yang sama. Maksimal 500 baris; peserta mengisi kursi kosong berurutan.
      </p>
      <form className="ff" action={action} style={{ marginTop: 14 }}>
        <input type="hidden" name="org" value={org} />
        <input type="hidden" name="code" value={code} />
        <div>
          <label htmlFor="bi-file">Berkas peserta</label>
          <input id="bi-file" name="file" type="file" accept=".csv,.xlsx,text/csv" required />
        </div>
        <FormMessage ok={state.ok && !state.report} message={state.report ? undefined : state.message} />
        <button className="kb1" type="submit" disabled={pending}>
          {pending ? "Memproses…" : "Unggah & proses"}
        </button>
      </form>
      {state.report && (
        <div style={{ marginTop: 18 }}>
          <p className="fh">
            <b>Hasil:</b>{" "}
            {Object.entries(state.summary ?? {})
              .map(([k, n]) => `${n} ${LABEL[k]?.text.toLowerCase() ?? k}`)
              .join(", ")}
          </p>
          <ul className="sp" style={{ marginTop: 8 }}>
            {state.report.map((row) => (
              <li key={row.row}>
                <span className="spl">
                  <b>
                    Baris {row.row}
                    {row.name ? ` · ${row.name}` : ""}
                  </b>
                  <small>
                    {row.email ?? "-"}
                    {row.seat ? ` · kursi ${row.seat}` : ""} — {row.message}
                  </small>
                </span>
                <b className="spv">
                  <span className={`jst ${LABEL[row.status]?.pill ?? "no"}`}>{LABEL[row.status]?.text ?? row.status}</span>
                </b>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
