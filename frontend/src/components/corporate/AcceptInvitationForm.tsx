"use client";

import { useActionState } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { acceptInvitationAction, type CorpState } from "@/lib/actions/corporate";

/** Persetujuan peserta (consent) sebelum ditetapkan ke kursi perusahaan. */
export default function AcceptInvitationForm({ token, userEmail, organization }: { token: string; userEmail: string; organization: string }) {
  const [state, action, pending] = useActionState<CorpState, FormData>(acceptInvitationAction, { ok: false });
  return (
    <form className="ff" action={action}>
      <input type="hidden" name="token" value={token} />
      <p className="fh">
        Anda masuk sebagai <b>{userEmail}</b>.
      </p>
      <label className="fc">
        <input type="checkbox" name="consent" required />
        <span>
          Saya setuju mengikuti training ini atas sponsor <b>{organization}</b>. Perusahaan dapat melihat kehadiran, progres belajar, hasil penilaian, dan status sertifikat untuk training yang mereka sponsori. Training lain milik saya tetap privat.
        </span>
      </label>
      <FormMessage ok={state.ok} message={state.message} />
      <button className="kb1" type="submit" disabled={pending}>
        {pending ? "Memproses…" : "Terima undangan"}
      </button>
    </form>
  );
}
