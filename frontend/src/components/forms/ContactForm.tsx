"use client";

import { useState } from "react";
import { waLink } from "@/lib/utils";

const TOPICS = ["Public Training", "Corporate / In-House Training", "Online Class", "Sertifikasi Internasional", "Program Kampus & Sekolah", "Lainnya"];

/**
 * Formulir konsultasi (PRD §5.1). Backend Laravel belum ada: untuk sementara pesan dikirim
 * ke WhatsApp resmi dengan teks terformat. Nanti diganti POST /api/v1/inquiries.
 */
export default function ContactForm() {
  const [sent, setSent] = useState(false);

  const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const lines = [
      "Halo PPTI - VMES, saya ingin konsultasi training.",
      `Nama: ${f.get("nama")}`,
      `Instansi: ${f.get("instansi") || "-"}`,
      `Email: ${f.get("email")}`,
      `Topik: ${f.get("topik")}`,
      `Pesan: ${f.get("pesan")}`,
    ];
    window.open(waLink(lines.join("\n")), "_blank", "noopener");
    setSent(true);
  };

  return (
    <form className="ff" onSubmit={onSubmit}>
      <div className="fg2">
        <div>
          <label htmlFor="c-nama">Nama lengkap</label>
          <input id="c-nama" name="nama" required placeholder="Nama Anda" autoComplete="name" />
        </div>
        <div>
          <label htmlFor="c-instansi">
            Perusahaan / Instansi <small>(opsional)</small>
          </label>
          <input id="c-instansi" name="instansi" placeholder="PT. Contoh Industri" autoComplete="organization" />
        </div>
      </div>
      <div className="fg2">
        <div>
          <label htmlFor="c-email">Email</label>
          <input id="c-email" name="email" type="email" required placeholder="nama@perusahaan.com" autoComplete="email" />
        </div>
        <div>
          <label htmlFor="c-wa">Nomor WhatsApp</label>
          <input id="c-wa" name="wa" type="tel" required placeholder="08xxxxxxxxxx" autoComplete="tel" />
        </div>
      </div>
      <div>
        <label htmlFor="c-topik">Topik konsultasi</label>
        <select id="c-topik" name="topik" defaultValue={TOPICS[0]}>
          {TOPICS.map((t) => (
            <option key={t}>{t}</option>
          ))}
        </select>
      </div>
      <div>
        <label htmlFor="c-pesan">Kebutuhan / pertanyaan</label>
        <textarea id="c-pesan" name="pesan" required placeholder="Ceritakan kebutuhan training Anda: topik, jumlah peserta, lokasi, dan waktu yang diinginkan." />
      </div>
      <label className="fc">
        <input type="checkbox" required />
        <span>Saya menyetujui pemrosesan data sesuai Kebijakan Privasi PPTI - VMES Training Center.</span>
      </label>
      <button className="kb1" type="submit">
        Kirim via WhatsApp
      </button>
      <p className="fh">Pesan akan dibuka di WhatsApp resmi kami (0851-2125-1285). Tim kami membalas pada hari kerja pukul 08.00 sampai 17.00 WIB.</p>
      {sent && <div className="fok">Terima kasih! Lanjutkan percakapan di jendela WhatsApp yang terbuka.</div>}
    </form>
  );
}
