"use client";

import Link from "next/link";
import { useActionState, useState } from "react";
import { FieldError, FormMessage } from "@/components/auth/AuthShell";
import { type Schedule, scheduleBatchTitle } from "@/data/schedules";
import { type RegistrationState, submitRegistrationAction } from "@/lib/actions/registration";
import { formatRupiah, waLink } from "@/lib/utils";

type Props = { schedules: Schedule[]; selectedId?: string; prefill?: { name: string; email: string; phone?: string | null; company?: string | null; position?: string | null } | null };

/**
 * Formulir pendaftaran (PRD §5.5) → server action → POST /api/v1/registrations.
 * Individu atau kolektif perusahaan; pendaftar login otomatis terhubung ke member.
 */
export default function RegisterForm({ schedules, selectedId, prefill }: Props) {
  const [state, action, pending] = useActionState<RegistrationState, FormData>(submitRegistrationAction, { ok: false });
  const [jadwal, setJadwal] = useState(selectedId && schedules.some((s) => s.id === selectedId) ? selectedId : schedules[0]?.id ?? "");
  const [type, setType] = useState<"individual" | "corporate">("individual");
  const s = schedules.find((x) => x.id === jadwal);

  if (state.ok && state.registration) {
    const r = state.registration;
    return (
      <div>
        <div className="fok">
          Pendaftaran diterima. Nomor registrasi Anda: <b>{r.number}</b>. Rincian dan email konfirmasi telah dikirim ke {r.email}.
        </div>
        <ul className="sp">
          <li>
            <span>Program</span>
            <b>{r.schedule?.title}</b>
          </li>
          <li>
            <span>Jadwal</span>
            <b>
              {r.schedule?.dateLabel} • {r.schedule?.city} • {r.schedule?.mode}
            </b>
          </li>
          <li>
            <span>Peserta</span>
            <b>{r.participantsCount} orang</b>
          </li>
          <li>
            <span>Estimasi biaya</span>
            <b>{r.total != null ? formatRupiah(r.total) : "-"}</b>
          </li>
          <li>
            <span>Status</span>
            <b>{r.statusLabel}</b>
          </li>
        </ul>
        <p className="fh">Tim admin meninjau pendaftaran dan mengirim rincian pembayaran dalam 1 hari kerja. Pantau statusnya di portal member.</p>
        <div className="kbt" style={{ gridTemplateColumns: "1fr 1fr" }}>
          <Link className="kb1" href={`/portal/pendaftaran/${r.number}`}>
            Buka Portal Member
          </Link>
          <a className="kb2 wa" href={waLink(`Halo, saya sudah mendaftar dengan nomor ${r.number}`)} target="_blank" rel="noopener">
            Hubungi Admin
          </a>
        </div>
      </div>
    );
  }

  return (
    <form className="ff" action={action}>
      <div>
        <label htmlFor="r-jadwal">Pilih jadwal / batch</label>
        <select id="r-jadwal" name="schedule" value={jadwal} onChange={(e) => setJadwal(e.target.value)}>
          {schedules.map((x) => (
            <option key={x.id} value={x.id} disabled={x.status === "no"}>
              {scheduleBatchTitle(x)} — {x.dateLabel} ({x.city}, {x.mode}){x.status === "no" ? " — Penuh" : ""}
            </option>
          ))}
        </select>
        {s && (
          <p className="fh" style={{ marginTop: 8 }}>
            Kode kelas {s.code} • {s.durationDays} hari • {s.time} • Sisa kuota {s.remaining} dari {s.quota} • {formatRupiah(s.price)}/peserta
          </p>
        )}
        <FieldError errors={state.errors} name="schedule" />
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="r-type">Jenis pendaftaran</label>
          <select id="r-type" name="type" value={type} onChange={(e) => setType(e.target.value as "individual" | "corporate")}>
            <option value="individual">Individu</option>
            <option value="corporate">Kolektif / Perusahaan</option>
          </select>
        </div>
        {type === "corporate" && (
          <div>
            <label htmlFor="r-count">Jumlah peserta</label>
            <input id="r-count" name="participants_count" type="number" min={1} max={500} defaultValue={2} required />
            <FieldError errors={state.errors} name="participants_count" />
          </div>
        )}
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="r-nama">{type === "corporate" ? "Nama PIC" : "Nama lengkap (sesuai sertifikat)"}</label>
          <input id="r-nama" name="name" required autoComplete="name" defaultValue={prefill?.name ?? ""} />
          <FieldError errors={state.errors} name="name" />
        </div>
        <div>
          <label htmlFor="r-email">Email</label>
          <input id="r-email" name="email" type="email" required autoComplete="email" defaultValue={prefill?.email ?? ""} />
          <FieldError errors={state.errors} name="email" />
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="r-wa">Nomor WhatsApp</label>
          <input id="r-wa" name="phone" type="tel" required autoComplete="tel" defaultValue={prefill?.phone ?? ""} />
          <FieldError errors={state.errors} name="phone" />
        </div>
        <div>
          <label htmlFor="r-instansi">
            Perusahaan / Instansi {type === "individual" && <small>(opsional)</small>}
          </label>
          <input id="r-instansi" name="company" autoComplete="organization" required={type === "corporate"} defaultValue={prefill?.company ?? ""} />
          <FieldError errors={state.errors} name="company" />
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="r-jabatan">
            Jabatan <small>(opsional)</small>
          </label>
          <input id="r-jabatan" name="position" autoComplete="organization-title" defaultValue={prefill?.position ?? ""} />
        </div>
      </div>
      {type === "corporate" && (
        <div>
          <label htmlFor="r-participants">
            Daftar peserta <small>(satu per baris: Nama, email, jabatan — opsional, bisa dilengkapi admin)</small>
          </label>
          <textarea id="r-participants" name="participants" placeholder={"Andi Pratama, andi@perusahaan.com, Teknisi\nBudi Santoso"} style={{ minHeight: 100 }} />
        </div>
      )}
      <div>
        <label htmlFor="r-catatan">
          Catatan <small>(kebutuhan khusus, pertanyaan, dll.)</small>
        </label>
        <textarea id="r-catatan" name="notes" style={{ minHeight: 90 }} />
      </div>
      <label className="fc">
        <input type="checkbox" name="consent" required />
        <span>
          Saya telah membaca <Link href="/syarat-ketentuan">Syarat &amp; Ketentuan</Link> serta <Link href="/kebijakan-privasi">Kebijakan Privasi</Link> PPTI - VMES Training Center.
        </span>
      </label>
      <FormMessage ok={state.ok} message={state.message} />
      <button className="kb1" type="submit" disabled={pending}>
        {pending ? "Mengirim…" : "Kirim Pendaftaran"}
      </button>
      <p className="fh">
        Nomor registrasi dan email konfirmasi dikirim otomatis. {prefill ? "Pendaftaran ini akan tercatat di akun Anda." : <>Punya akun? <Link href="/login?next=/daftar">Masuk</Link> agar pendaftaran tercatat di portal member.</>}
      </p>
    </form>
  );
}
