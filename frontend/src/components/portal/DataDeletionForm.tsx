"use client";

import { useActionState, useState, useTransition } from "react";
import { FieldError, FormMessage } from "@/components/auth/AuthShell";
import type { FormState } from "@/lib/actions/auth";
import { cancelDeletionAction, requestDeletionAction } from "@/lib/actions/privacy";

/** Formulir pengajuan penghapusan data: konfirmasi ulang dengan email akun atau kata sandi, alasan opsional. */
export function DataDeletionForm({ email }: { email: string }) {
  const [state, action, pending] = useActionState<FormState, FormData>(requestDeletionAction, { ok: false });
  const [by, setBy] = useState<"email" | "password">("email");
  return (
    <form className="ff" action={action} style={{ marginTop: 16 }}>
      <div>
        <label>Konfirmasi dengan</label>
        <label className="fc">
          <input type="radio" name="confirm_by" value="email" checked={by === "email"} onChange={() => setBy("email")} /> <span>Mengetik alamat email akun</span>
        </label>
        <label className="fc">
          <input type="radio" name="confirm_by" value="password" checked={by === "password"} onChange={() => setBy("password")} /> <span>Kata sandi</span>
        </label>
      </div>
      <div>
        <label htmlFor="dd-confirm">
          {by === "email" ? (
            <>
              Ketik email akun <small>({email})</small>
            </>
          ) : (
            "Kata sandi akun"
          )}
        </label>
        <input
          id="dd-confirm"
          name="confirmation"
          type={by === "email" ? "email" : "password"}
          autoComplete={by === "email" ? "off" : "current-password"}
          required
          aria-describedby="dd-confirm-help"
        />
        <p id="dd-confirm-help" className="fh" style={{ marginTop: 6 }}>
          Konfirmasi ini memastikan permintaan benar-benar dari pemilik akun.
        </p>
        <FieldError errors={state.errors} name="confirmation" />
      </div>
      <div>
        <label htmlFor="dd-reason">
          Alasan <small>(opsional)</small>
        </label>
        <textarea id="dd-reason" name="reason" maxLength={1000} rows={3} style={{ minHeight: 90 }} />
      </div>
      <label className="fc">
        <input type="checkbox" name="understand" required />{" "}
        <span>Saya memahami data yang akan dihapus dan yang tetap disimpan, serta bahwa akun tidak dapat dipakai lagi setelah penghapusan selesai.</span>
      </label>
      <FormMessage ok={state.ok} message={state.message} />
      <button className="kb1" type="submit" disabled={pending} style={{ cursor: "pointer", fontFamily: "inherit" }}>
        {pending ? "Mengirim…" : "Ajukan penghapusan data"}
      </button>
    </form>
  );
}

/** Batalkan permintaan yang masih menunggu diproses. */
export function CancelDeletionButton() {
  const [msg, setMsg] = useState<FormState | null>(null);
  const [pending, start] = useTransition();
  return (
    <div style={{ marginTop: 14 }}>
      <FormMessage ok={msg?.ok} message={msg?.message} />
      <button className="kb2" type="button" disabled={pending} style={{ display: "inline-block", cursor: "pointer", fontFamily: "inherit" }} onClick={() => start(async () => setMsg(await cancelDeletionAction()))}>
        {pending ? "Membatalkan…" : "Batalkan permintaan"}
      </button>
    </div>
  );
}
