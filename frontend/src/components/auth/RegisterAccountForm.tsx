"use client";

import Link from "next/link";
import { useActionState } from "react";
import { type FormState, registerAction } from "@/lib/actions/auth";
import { FieldError, FormMessage } from "./AuthShell";

export default function RegisterAccountForm({ next }: { next: string }) {
  const [state, action, pending] = useActionState<FormState, FormData>(registerAction, { ok: false });
  return (
    <form className="ff" action={action}>
      <input type="hidden" name="next" value={next} />
      <div>
        <label htmlFor="r-name">Nama lengkap</label>
        <input id="r-name" name="name" required autoComplete="name" />
        <FieldError errors={state.errors} name="name" />
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="r-email">Email</label>
          <input id="r-email" name="email" type="email" required autoComplete="email" />
          <FieldError errors={state.errors} name="email" />
        </div>
        <div>
          <label htmlFor="r-phone">Nomor WhatsApp</label>
          <input id="r-phone" name="phone" type="tel" required autoComplete="tel" />
          <FieldError errors={state.errors} name="phone" />
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="r-pass">Sandi <small>(min. 8 karakter)</small></label>
          <input id="r-pass" name="password" type="password" required minLength={8} autoComplete="new-password" />
          <FieldError errors={state.errors} name="password" />
        </div>
        <div>
          <label htmlFor="r-pass2">Ulangi sandi</label>
          <input id="r-pass2" name="password_confirmation" type="password" required minLength={8} autoComplete="new-password" />
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="r-company">Perusahaan / instansi <small>(opsional)</small></label>
          <input id="r-company" name="company" autoComplete="organization" />
        </div>
        <div>
          <label htmlFor="r-city">Kota <small>(opsional)</small></label>
          <input id="r-city" name="city" autoComplete="address-level2" />
        </div>
      </div>
      <label className="fc">
        <input type="checkbox" name="consent" required />
        <span>
          Saya menyetujui <Link href="/syarat-ketentuan">Syarat &amp; Ketentuan</Link> dan <Link href="/kebijakan-privasi">Kebijakan Privasi</Link> PPTI - VMES Training Center.
        </span>
      </label>
      <FormMessage ok={state.ok} message={state.message} />
      <button className="kb1" type="submit" disabled={pending}>
        {pending ? "Memproses…" : "Buat Akun"}
      </button>
    </form>
  );
}
