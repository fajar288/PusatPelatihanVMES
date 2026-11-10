"use client";

import { useState } from "react";
import { type Schedule, scheduleBatchTitle } from "@/data/schedules";
import { waLink } from "@/lib/utils";

type Props = { schedules: Schedule[]; selectedId?: string };

/**
 * Formulir pendaftaran individu (Phase 1E, PRD §5.5) — versi awal tanpa backend:
 * data dirangkum dan dikirim ke WhatsApp admin; nomor registrasi & status menyusul saat API Laravel siap.
 */
export default function RegisterForm({ schedules, selectedId }: Props) {
  const [sent, setSent] = useState(false);
  const [jadwal, setJadwal] = useState(selectedId && schedules.some((s) => s.id === selectedId) ? selectedId : schedules[0]?.id ?? "");
  const s = schedules.find((x) => x.id === jadwal);

  const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const lines = [
      "Halo PPTI - VMES, saya ingin mendaftar training.",
      `Program: ${s ? `${scheduleBatchTitle(s)} (${s.code})` : "-"}`,
      `Tanggal: ${s?.dateLabel ?? "-"} • ${s?.city ?? ""} • ${s?.mode ?? ""}`,
      `Nama: ${f.get("nama")}`,
      `Email: ${f.get("email")}`,
      `WhatsApp: ${f.get("wa")}`,
      `Instansi: ${f.get("instansi") || "-"}`,
      `Jabatan: ${f.get("jabatan") || "-"}`,
      `Jenis pendaftaran: ${f.get("jenis")}`,
      `Catatan: ${f.get("catatan") || "-"}`,
    ];
    window.open(waLink(lines.join("\n")), "_blank", "noopener");
    setSent(true);
  };

  return (
    <form className="ff" onSubmit={onSubmit}>
      <div>
        <label htmlFor="r-jadwal">Pilih jadwal / batch</label>
        <select id="r-jadwal" name="jadwal" value={jadwal} onChange={(e) => setJadwal(e.target.value)}>
          {schedules.map((x) => (
            <option key={x.id} value={x.id} disabled={x.status === "no"}>
              {scheduleBatchTitle(x)} — {x.dateLabel} ({x.city}, {x.mode}){x.status === "no" ? " — Penuh" : ""}
            </option>
          ))}
        </select>
        {s && (
          <p className="fh" style={{ marginTop: 8 }}>
            Kode kelas {s.code} • {s.durationDays} hari • {s.time} • Sisa kuota {s.remaining} dari {s.quota}
          </p>
        )}
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="r-nama">Nama lengkap (sesuai sertifikat)</label>
          <input id="r-nama" name="nama" required autoComplete="name" />
        </div>
        <div>
          <label htmlFor="r-email">Email</label>
          <input id="r-email" name="email" type="email" required autoComplete="email" />
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="r-wa">Nomor WhatsApp</label>
          <input id="r-wa" name="wa" type="tel" required autoComplete="tel" />
        </div>
        <div>
          <label htmlFor="r-jenis">Jenis pendaftaran</label>
          <select id="r-jenis" name="jenis" defaultValue="Individu">
            <option>Individu</option>
            <option>Kolektif / Perusahaan</option>
          </select>
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="r-instansi">
            Perusahaan / Instansi <small>(opsional)</small>
          </label>
          <input id="r-instansi" name="instansi" autoComplete="organization" />
        </div>
        <div>
          <label htmlFor="r-jabatan">
            Jabatan <small>(opsional)</small>
          </label>
          <input id="r-jabatan" name="jabatan" autoComplete="organization-title" />
        </div>
      </div>
      <div>
        <label htmlFor="r-catatan">
          Catatan <small>(jumlah peserta, kebutuhan khusus, dll.)</small>
        </label>
        <textarea id="r-catatan" name="catatan" style={{ minHeight: 90 }} />
      </div>
      <label className="fc">
        <input type="checkbox" required />
        <span>Saya telah membaca Syarat & Ketentuan serta Kebijakan Privasi PPTI - VMES Training Center.</span>
      </label>
      <button className="kb1" type="submit">
        Kirim Pendaftaran
      </button>
      <p className="fh">Data pendaftaran akan dikirim ke admin melalui WhatsApp. Nomor registrasi dan rincian pembayaran dikirim dalam 1 hari kerja.</p>
      {sent && <div className="fok">Pendaftaran terkirim. Lanjutkan percakapan di jendela WhatsApp yang terbuka.</div>}
    </form>
  );
}
