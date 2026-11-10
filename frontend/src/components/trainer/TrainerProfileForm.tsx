"use client";

import { useActionState } from "react";
import { FieldError, FormMessage } from "@/components/auth/AuthShell";
import { type FormState } from "@/lib/actions/auth";
import { updateTrainerProfileAction } from "@/lib/actions/trainer";
import type { TrainerProfile } from "@/lib/trainer";

export default function TrainerProfileForm({ p }: { p: TrainerProfile }) {
  const [state, action, pending] = useActionState<FormState, FormData>(updateTrainerProfileAction, { ok: false });
  return (
    <form className="ff" action={action}>
      <div className="fg2">
        <div>
          <label htmlFor="t-name">Nama</label>
          <input id="t-name" value={p.name} disabled />
          <p className="fh">Nama & foto diubah oleh admin.</p>
        </div>
        <div>
          <label htmlFor="t-title">Jabatan / spesialisasi</label>
          <input id="t-title" name="title" defaultValue={p.title ?? ""} />
          <FieldError errors={state.errors} name="title" />
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="t-phone">Telepon</label>
          <input id="t-phone" name="phone" defaultValue={p.phone ?? ""} />
        </div>
        <div>
          <label htmlFor="t-exp">Keahlian <small>(pisahkan koma)</small></label>
          <input id="t-exp" name="expertise" defaultValue={p.expertise.join(", ")} />
        </div>
      </div>
      <div>
        <label htmlFor="t-cert">Sertifikasi <small>(pisahkan koma)</small></label>
        <input id="t-cert" name="certifications" defaultValue={p.certifications.join(", ")} />
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="t-edu">Pendidikan</label>
          <textarea id="t-edu" name="education" defaultValue={p.education ?? ""} style={{ minHeight: 80 }} />
        </div>
        <div>
          <label htmlFor="t-xp">Pengalaman</label>
          <textarea id="t-xp" name="experience" defaultValue={p.experience ?? ""} style={{ minHeight: 80 }} />
        </div>
      </div>
      <div>
        <label htmlFor="t-port">Portofolio / tautan</label>
        <textarea id="t-port" name="portfolio" defaultValue={p.portfolio ?? ""} style={{ minHeight: 60 }} />
      </div>
      <div>
        <label htmlFor="t-bio">Bio singkat</label>
        <textarea id="t-bio" name="bio" defaultValue={p.bio ?? ""} style={{ minHeight: 80 }} />
      </div>
      <FormMessage ok={state.ok} message={state.message} />
      <button className="kb1" type="submit" disabled={pending}>
        {pending ? "Menyimpan…" : "Simpan Profil"}
      </button>
    </form>
  );
}
