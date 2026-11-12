"use client";

import { useActionState, useState, useTransition } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { cancelRegistrationAction, type RegistrationState, uploadPaymentProofAction } from "@/lib/actions/registration";
import PaymentAccounts from "@/components/portal/PaymentAccounts";
import type { PaymentAccountInfo, RegistrationInfo } from "@/lib/portal-types";

/** Aksi pendaftar: unggah bukti transfer (saat menunggu pembayaran/verifikasi) dan batalkan (sebelum terkonfirmasi). */
export default function RegistrationActions({ r, accounts }: { r: RegistrationInfo; accounts: PaymentAccountInfo[] }) {
  const [upState, upAction, upPending] = useActionState<RegistrationState, FormData>(uploadPaymentProofAction, { ok: false });
  const [cancelMsg, setCancelMsg] = useState<RegistrationState | null>(null);
  const [pending, start] = useTransition();
  const canUpload = r.status === "waiting_payment" || r.status === "payment_verification";

  return (
    <>
      {canUpload && (
        <section className="kbx pcard">
          <h2>Unggah Bukti Pembayaran</h2>
          <PaymentAccounts accounts={accounts} intro="Transfer sesuai nominal tagihan ke salah satu rekening resmi berikut:" />
          <p className="fh" style={{ marginTop: 10 }}>
            Lalu unggah bukti transfer (JPG/PNG/PDF maks. 4 MB).
          </p>
          <form className="ff" action={upAction} style={{ marginTop: 16 }}>
            <input type="hidden" name="number" value={r.number} />
            <div>
              <label htmlFor="pf-file">Berkas bukti transfer</label>
              <input id="pf-file" name="proof" type="file" accept="image/jpeg,image/png,application/pdf" required />
            </div>
            <div>
              <label htmlFor="pf-note">
                Catatan <small>(bank pengirim, nama rekening, dll.)</small>
              </label>
              <input id="pf-note" name="note" />
            </div>
            <FormMessage ok={upState.ok} message={upState.message} />
            <button className="kb1" type="submit" disabled={upPending}>
              {upPending ? "Mengunggah…" : "Kirim Bukti Pembayaran"}
            </button>
          </form>
        </section>
      )}

      {r.cancellable && (
        <section className="kbx pcard">
          <h2>Batalkan Pendaftaran</h2>
          <p className="fh" style={{ marginTop: 6 }}>
            Pembatalan mengikuti Syarat &amp; Ketentuan. Setelah terkonfirmasi, pembatalan hanya lewat admin.
          </p>
          <FormMessage ok={cancelMsg?.ok} message={cancelMsg?.message} />
          <button
            className="kb2"
            type="button"
            disabled={pending}
            style={{ marginTop: 14 }}
            onClick={() => {
              const reason = window.prompt("Alasan pembatalan (opsional):") ?? "";
              if (reason === null) return;
              start(async () => setCancelMsg(await cancelRegistrationAction(r.number, reason)));
            }}
          >
            {pending ? "Memproses…" : "Batalkan pendaftaran ini"}
          </button>
        </section>
      )}
    </>
  );
}
