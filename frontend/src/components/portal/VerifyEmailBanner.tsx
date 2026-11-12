"use client";

import { useState, useTransition } from "react";
import { resendVerificationAction } from "@/lib/actions/corporate";

/** Pengingat verifikasi email di portal (Roadmap 5B): email terverifikasi menyambungkan histori training ke satu akun. */
export default function VerifyEmailBanner({ email }: { email: string }) {
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [pending, start] = useTransition();
  return (
    <div className="pverify" role="status">
      <div>
        <b>Verifikasi email Anda</b>
        <p>
          Kami mengirim tautan verifikasi ke <b>{email}</b>. Email terverifikasi memastikan training yang didaftarkan perusahaan Anda langsung tersambung ke akun ini.
        </p>
        {msg && <p className={msg.ok ? "ok" : "err"}>{msg.text}</p>}
      </div>
      <button
        type="button"
        className="kb2"
        disabled={pending}
        onClick={() =>
          start(async () => {
            const r = await resendVerificationAction();
            setMsg({ ok: r.ok, text: r.message ?? (r.ok ? "Tautan dikirim." : "Gagal mengirim.") });
          })
        }
      >
        {pending ? "Mengirim…" : "Kirim ulang tautan"}
      </button>
    </div>
  );
}
