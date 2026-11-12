"use client";

import { useActionState } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { type InvoiceProofState, uploadInvoiceProofAction } from "@/lib/actions/invoice";
import type { PaymentAccountInfo } from "@/lib/portal-types";
import { formatRupiah } from "@/lib/utils";

/**
 * Form unggah bukti transfer untuk satu invoice (portal member & portal perusahaan). Nominal bawaan = sisa tagihan;
 * pembayar boleh mengisi nominal yang benar-benar ditransfer — selisih dicatat finance saat verifikasi.
 */
export default function InvoiceProofForm({
  scope,
  number,
  outstanding,
  accounts,
  payer,
  replacing,
}: {
  scope: string;
  number: string;
  outstanding: number;
  accounts: PaymentAccountInfo[];
  payer?: string;
  replacing?: boolean;
}) {
  const [state, action, pending] = useActionState<InvoiceProofState, FormData>(uploadInvoiceProofAction, { ok: false });
  const err = (k: string) => state.errors?.[k]?.[0];

  return (
    <form className="ff" action={action} style={{ marginTop: 14 }}>
      <input type="hidden" name="scope" value={scope} />
      <input type="hidden" name="number" value={number} />
      {replacing && (
        <p className="fh" style={{ marginTop: 0 }}>
          Bukti sebelumnya masih diverifikasi. Mengunggah bukti baru akan menggantikannya.
        </p>
      )}
      <div className="fg2">
        <div>
          <label htmlFor={`pf-amount-${number}`}>Nominal ditransfer (Rp)</label>
          <input id={`pf-amount-${number}`} name="amount" inputMode="numeric" defaultValue={String(Math.round(outstanding))} aria-describedby={`pf-amount-h-${number}`} />
          <small id={`pf-amount-h-${number}`} className="fh">
            Sisa tagihan {formatRupiah(outstanding)}
          </small>
          {err("amount") && <small className="ferr">{err("amount")}</small>}
        </div>
        <div>
          <label htmlFor={`pf-date-${number}`}>Tanggal transfer</label>
          <input id={`pf-date-${number}`} name="transfer_date" type="date" aria-describedby={`pf-date-h-${number}`} />
          <small id={`pf-date-h-${number}`} className="fh">
            Kosongkan bila hari ini
          </small>
          {err("transfer_date") && <small className="ferr">{err("transfer_date")}</small>}
        </div>
      </div>
      <div className="fg2">
        {accounts.length > 0 && (
          <div>
            <label htmlFor={`pf-acc-${number}`}>Rekening tujuan</label>
            <select id={`pf-acc-${number}`} name="payment_account_id" defaultValue={accounts.length === 1 ? String(accounts[0].id) : ""}>
              <option value="">— Pilih rekening —</option>
              {accounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.bank} {a.type === "virtual_account" ? "(VA) " : ""}
                  {a.number}
                </option>
              ))}
            </select>
          </div>
        )}
        <div>
          <label htmlFor={`pf-payer-${number}`}>Nama pengirim</label>
          <input id={`pf-payer-${number}`} name="payer_name" defaultValue={payer ?? ""} maxLength={120} />
        </div>
      </div>
      <div>
        <label htmlFor={`pf-file-${number}`}>Berkas bukti transfer (JPG/PNG/PDF maks. 4 MB)</label>
        <input id={`pf-file-${number}`} name="proof" type="file" accept="image/jpeg,image/png,application/pdf" required />
        {err("proof") && <small className="ferr">{err("proof")}</small>}
      </div>
      <div>
        <label htmlFor={`pf-note-${number}`}>
          Catatan <small>(opsional)</small>
        </label>
        <input id={`pf-note-${number}`} name="note" maxLength={500} />
      </div>
      <FormMessage ok={state.ok} message={state.message} />
      <button className="kb1" type="submit" disabled={pending}>
        {pending ? "Mengunggah…" : "Kirim Bukti Pembayaran"}
      </button>
    </form>
  );
}
