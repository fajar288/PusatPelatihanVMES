"use client";

import Link from "next/link";
import { useActionState } from "react";
import { type FormState, forgotPasswordAction, resetPasswordAction } from "@/lib/actions/auth";
import { FieldError, FormMessage } from "./AuthShell";

export function ForgotForm() {
  const [state, action, pending] = useActionState<FormState, FormData>(forgotPasswordAction, { ok: false });
  return (
    <form className="ff" action={action}>
      <div>
        <label htmlFor="f-email">Email akun</label>
        <input id="f-email" name="email" type="email" required autoComplete="email" />
        <FieldError errors={state.errors} name="email" />
      </div>
      <FormMessage ok={state.ok} message={state.message} />
      <button className="kb1" type="submit" disabled={pending}>
        {pending ? "Mengirim…" : "Kirim Tautan Reset"}
      </button>
    </form>
  );
}

export function ResetForm({ token, email }: { token: string; email: string }) {
  const [state, action, pending] = useActionState<FormState, FormData>(resetPasswordAction, { ok: false });
  return (
    <form className="ff" action={action}>
      <input type="hidden" name="token" value={token} />
      <div>
        <label htmlFor="rs-email">Email</label>
        <input id="rs-email" name="email" type="email" required defaultValue={email} autoComplete="email" />
        <FieldError errors={state.errors} name="email" />
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="rs-pass">Sandi baru</label>
          <input id="rs-pass" name="password" type="password" required minLength={8} autoComplete="new-password" />
          <FieldError errors={state.errors} name="password" />
        </div>
        <div>
          <label htmlFor="rs-pass2">Ulangi sandi</label>
          <input id="rs-pass2" name="password_confirmation" type="password" required minLength={8} autoComplete="new-password" />
        </div>
      </div>
      <FormMessage ok={state.ok} message={state.message} />
      {state.ok ? (
        <Link className="kb1" href="/login">
          Masuk
        </Link>
      ) : (
        <button className="kb1" type="submit" disabled={pending}>
          {pending ? "Menyimpan…" : "Simpan Sandi Baru"}
        </button>
      )}
    </form>
  );
}
