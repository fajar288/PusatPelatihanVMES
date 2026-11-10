"use client";

import Link from "next/link";
import { useActionState } from "react";
import { type FormState, loginAction } from "@/lib/actions/auth";
import { FieldError, FormMessage } from "./AuthShell";

export default function LoginForm({ next }: { next: string }) {
  const [state, action, pending] = useActionState<FormState, FormData>(loginAction, { ok: false });
  return (
    <form className="ff" action={action}>
      <input type="hidden" name="next" value={next} />
      <div>
        <label htmlFor="l-email">Email</label>
        <input id="l-email" name="email" type="email" required autoComplete="email" />
        <FieldError errors={state.errors} name="email" />
      </div>
      <div>
        <label htmlFor="l-pass">Sandi</label>
        <input id="l-pass" name="password" type="password" required autoComplete="current-password" />
        <FieldError errors={state.errors} name="password" />
      </div>
      <FormMessage ok={state.ok} message={state.message} />
      <button className="kb1" type="submit" disabled={pending}>
        {pending ? "Memproses…" : "Masuk"}
      </button>
      <p className="fh">
        <Link href="/lupa-password">Lupa sandi?</Link>
      </p>
    </form>
  );
}
