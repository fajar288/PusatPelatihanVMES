"use client";

import { useState, useTransition } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { requestTransitionAction } from "@/lib/actions/corporate";
import type { CorporateRequest } from "@/lib/corporate";

/** Tombol alur permintaan: kirim, terima penawaran, setujui (owner), batalkan. Server memeriksa ulang peran & status. */
export default function RequestActions({ org, code, can, status, requiresApproval }: { org: string; code: string; can: CorporateRequest["can"]; status: string; requiresApproval: boolean }) {
  const [msg, setMsg] = useState<{ ok: boolean; message?: string } | null>(null);
  const [pending, start] = useTransition();
  const run = (action: "submit" | "accept-quote" | "approve" | "cancel", confirmText?: string) => {
    let note: string | undefined;
    if (action === "cancel") {
      const r = window.prompt(confirmText ?? "Alasan pembatalan:");
      if (r === null) return;
      note = r;
    }
    start(async () => setMsg(await requestTransitionAction(org, code, action, note)));
  };
  const any = can.submit || can.acceptQuote || can.approve || can.cancel;
  if (!any && !msg) {
    return status === "approval" ? <p className="fh" style={{ marginTop: 12 }}>Menunggu persetujuan Corporate Owner.</p> : null;
  }
  return (
    <div style={{ marginTop: 14 }}>
      <div className="flex flex-wrap gap-2">
        {can.submit && (
          <button type="button" className="kb1 px-5! py-2.5!" disabled={pending} onClick={() => run("submit")}>
            Kirim ke VMES
          </button>
        )}
        {can.acceptQuote && (
          <button type="button" className="kb1 px-5! py-2.5!" disabled={pending} onClick={() => run("accept-quote")}>
            {requiresApproval ? "Terima penawaran & ajukan persetujuan" : "Terima penawaran"}
          </button>
        )}
        {can.approve && (
          <button type="button" className="kb1 px-5! py-2.5!" disabled={pending} onClick={() => run("approve")}>
            Setujui permintaan
          </button>
        )}
        {can.cancel && (
          <button type="button" className="kb2 px-5! py-2.5!" disabled={pending} onClick={() => run("cancel", "Alasan membatalkan permintaan ini:")}>
            Batalkan permintaan
          </button>
        )}
      </div>
      {msg && <FormMessage ok={msg.ok} message={msg.message} />}
    </div>
  );
}
