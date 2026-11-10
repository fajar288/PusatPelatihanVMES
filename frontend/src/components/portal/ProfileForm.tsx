"use client";

import { useActionState } from "react";
import { FieldError, FormMessage } from "@/components/auth/AuthShell";
import { type FormState } from "@/lib/actions/auth";
import { updateProfileAction } from "@/lib/actions/profile";
import type { PortalUser } from "@/lib/portal-types";

export default function ProfileForm({ user }: { user: PortalUser }) {
  const [state, action, pending] = useActionState<FormState, FormData>(updateProfileAction, { ok: false });
  const m = user.member;
  return (
    <form className="ff" action={action}>
      <div className="fg2">
        <div>
          <label htmlFor="p-name">Nama lengkap (sesuai sertifikat)</label>
          <input id="p-name" name="name" required defaultValue={user.name} />
          <FieldError errors={state.errors} name="name" />
        </div>
        <div>
          <label htmlFor="p-email">Email</label>
          <input id="p-email" value={user.email} disabled />
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="p-phone">Nomor WhatsApp</label>
          <input id="p-phone" name="phone" type="tel" defaultValue={m?.phone ?? ""} />
          <FieldError errors={state.errors} name="phone" />
        </div>
        <div>
          <label htmlFor="p-birth">Tanggal lahir</label>
          <input id="p-birth" name="birth_date" type="date" defaultValue={m?.birthDate ?? ""} />
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="p-gender">Jenis kelamin</label>
          <select id="p-gender" name="gender" defaultValue={m?.gender ?? ""}>
            <option value="">-</option>
            <option value="male">Laki-laki</option>
            <option value="female">Perempuan</option>
          </select>
        </div>
        <div>
          <label htmlFor="p-edu">Pendidikan terakhir</label>
          <input id="p-edu" name="education" defaultValue={m?.education ?? ""} placeholder="S1 Teknik Elektro" />
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="p-company">Perusahaan / instansi</label>
          <input id="p-company" name="company" defaultValue={m?.company ?? ""} />
        </div>
        <div>
          <label htmlFor="p-position">Jabatan</label>
          <input id="p-position" name="position" defaultValue={m?.position ?? ""} />
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="p-city">Kota</label>
          <input id="p-city" name="city" defaultValue={m?.city ?? ""} />
        </div>
        <div>
          <label htmlFor="p-address">Alamat</label>
          <input id="p-address" name="address" defaultValue={m?.address ?? ""} />
        </div>
      </div>
      <div>
        <label>Preferensi komunikasi</label>
        <label className="fc">
          <input type="checkbox" name="pref_email" defaultChecked={m?.communicationPref.email ?? true} /> <span>Email</span>
        </label>
        <label className="fc">
          <input type="checkbox" name="pref_whatsapp" defaultChecked={m?.communicationPref.whatsapp ?? true} /> <span>WhatsApp</span>
        </label>
      </div>
      <FormMessage ok={state.ok} message={state.message} />
      <button className="kb1" type="submit" disabled={pending}>
        {pending ? "Menyimpan…" : "Simpan Profil"}
      </button>
    </form>
  );
}
