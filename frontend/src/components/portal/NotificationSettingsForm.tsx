"use client";

import { useActionState } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import PushToggle from "@/components/pwa/PushToggle";
import type { FormState } from "@/lib/actions/auth";
import { saveNotificationPrefsAction } from "@/lib/actions/notifications";
import type { NotificationSettings } from "@/lib/portal-types";

/** Preferensi notifikasi per kategori (email & push) + aktivasi push perangkat ini. Notifikasi di portal selalu aktif. */
export default function NotificationSettingsForm({ settings }: { settings: NotificationSettings }) {
  const [state, action, pending] = useActionState<FormState, FormData>(saveNotificationPrefsAction, { ok: false });
  return (
    <>
      <PushToggle publicKey={settings.push.publicKey} available={settings.push.available} />
      <form action={action} className="nset-form">
        <input type="hidden" name="categories" value={settings.categories.map((c) => c.key).join(",")} />
        <div className="nset" role="table" aria-label="Preferensi notifikasi">
          <div className="nset-h" role="row">
            <span role="columnheader">Jenis notifikasi</span>
            <span role="columnheader">Email</span>
            <span role="columnheader">Push</span>
          </div>
          {settings.categories.map((c) => (
            <div className="nset-r" role="row" key={c.key}>
              <span role="cell">
                <b>{c.label}</b>
                <small>{c.description}</small>
              </span>
              <label role="cell" className="nset-c">
                <input type="checkbox" name={`mail_${c.key}`} defaultChecked={c.mail} disabled={!settings.emailEnabled} />
                {/* input disabled tidak ikut terkirim → pertahankan nilai tersimpan */}
                {!settings.emailEnabled && c.mail && <input type="hidden" name={`mail_${c.key}`} value="on" />}
                <span className="sr-only">Email {c.label}</span>
              </label>
              <label role="cell" className="nset-c">
                <input type="checkbox" name={`push_${c.key}`} defaultChecked={c.push} />
                <span className="sr-only">Push {c.label}</span>
              </label>
            </div>
          ))}
        </div>
        {!settings.emailEnabled && <p className="nset-note">Email notifikasi dimatikan di Profil (preferensi komunikasi). Email penting seperti status pendaftaran dan sertifikat tetap dikirim.</p>}
        <p className="nset-note">Notifikasi di portal selalu aktif. Email penting (status pendaftaran, tagihan, perubahan jadwal, sertifikat) selalu dikirim.</p>
        <FormMessage ok={state.ok} message={state.message} />
        <button type="submit" className="btn" disabled={pending}>
          {pending ? "Menyimpan…" : "Simpan pengaturan"}
        </button>
      </form>
    </>
  );
}
