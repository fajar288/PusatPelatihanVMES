"use client";

import { useState, useTransition } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { cancelRegistrationAction, type RegistrationState } from "@/lib/actions/registration";
import InvoiceProofForm from "@/components/portal/InvoiceProofForm";
import PaymentAccounts from "@/components/portal/PaymentAccounts";
import type { PaymentAccountInfo, RegistrationInfo } from "@/lib/portal-types";

/** Aksi pendaftar: unggah bukti transfer untuk invoice terbuka pertama dan batalkan (sebelum terkonfirmasi). */
export default function RegistrationActions({ r, accounts, payer }: { r: RegistrationInfo; accounts: PaymentAccountInfo[]; payer?: string }) {
  const [cancelMsg, setCancelMsg] = useState<RegistrationState | null>(null);
  const [pending, start] = useTransition();
  // invoice yang masih bisa dibayar (termin paling awal); invoice lunas/kedaluwarsa tidak menerima bukti
  const invoice = (r.status === "waiting_payment" || r.status === "payment_verification") ? r.invoices?.find((i) => i.payable) : undefined;

  return (
    <>
      {invoice && (
        <section className="kbx pcard">
          <h2>Unggah Bukti Pembayaran</h2>
          <PaymentAccounts accounts={accounts} intro={`Transfer sesuai sisa tagihan invoice ${invoice.number} ke salah satu rekening resmi berikut:`} />
          <InvoiceProofForm scope="me" number={invoice.number} outstanding={invoice.outstanding} accounts={accounts} payer={payer} replacing={invoice.awaitingVerification} />
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
