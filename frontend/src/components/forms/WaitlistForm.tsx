"use client";

import { useActionState } from "react";
import { FieldError, FormMessage } from "@/components/auth/AuthShell";
import { joinWaitlistAction, type WaitlistState } from "@/lib/actions/waitlist";

/** Daftar tunggu untuk kelas yang penuh (PRD §5.4). */
export default function WaitlistForm({ code }: { code: string }) {
  const [state, action, pending] = useActionState<WaitlistState, FormData>(joinWaitlistAction, { ok: false });
  if (state.ok) return <FormMessage ok message={state.message} />;
  return (
    <form className="ff" action={action}>
      <input type="hidden" name="schedule" value={code} />
      <div>
        <label htmlFor="w-name">Nama</label>
        <input id="w-name" name="name" required autoComplete="name" />
        <FieldError errors={state.errors} name="name" />
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="w-email">Email</label>
          <input id="w-email" name="email" type="email" required autoComplete="email" />
          <FieldError errors={state.errors} name="email" />
        </div>
        <div>
          <label htmlFor="w-phone">WhatsApp</label>
          <input id="w-phone" name="phone" type="tel" autoComplete="tel" />
        </div>
      </div>
      <div>
        <label htmlFor="w-count">Jumlah peserta</label>
        <input id="w-count" name="participants_count" type="number" min={1} max={100} defaultValue={1} />
      </div>
      <FormMessage ok={state.ok} message={state.message} />
      <button className="kb1" type="submit" disabled={pending}>
        {pending ? "Mengirim…" : "Masuk Daftar Tunggu"}
      </button>
    </form>
  );
}
