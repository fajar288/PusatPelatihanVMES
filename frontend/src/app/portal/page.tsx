import Link from "next/link";
import { EmptyState, PortalCard, RegistrationRow } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { RegistrationInfo } from "@/lib/portal-types";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

const MEMBER_STATUS: Record<string, string> = { registered: "Terdaftar", active: "Peserta aktif", alumni: "Alumni", inactive: "Tidak aktif" };

/** /portal — dashboard: identitas member, ringkasan status pendaftaran, pendaftaran terbaru. */
export default async function PortalDashboard() {
  const { user, token } = await requireUser("/portal");
  const regs = (await api<{ data: RegistrationInfo[] }>("/me/registrations", { token })).data;
  const m = user.member;
  const counts = {
    active: regs.filter((r) => r.status === "confirmed").length,
    process: regs.filter((r) => ["pending", "under_review", "waiting_payment", "payment_verification"].includes(r.status)).length,
    pay: regs.filter((r) => r.status === "waiting_payment").length,
  };

  return (
    <>
      <div className="kic pstats">
        <div className="kic1">
          <small>Nomor member</small>
          <b>{m?.memberNumber ?? "-"}</b>
        </div>
        <div className="kic1">
          <small>Status keanggotaan</small>
          <b>{MEMBER_STATUS[m?.status ?? "registered"]}</b>
        </div>
        <div className="kic1">
          <small>Pelatihan terkonfirmasi</small>
          <b>{counts.active}</b>
        </div>
        <div className="kic1">
          <small>Dalam proses</small>
          <b>{counts.process}</b>
        </div>
      </div>

      {counts.pay > 0 && (
        <div className="fok" style={{ marginTop: 20, background: "#fff7e0", borderColor: "#f1dc9b", color: "#8a6100" }}>
          Ada {counts.pay} pendaftaran menunggu pembayaran. Buka detail pendaftaran untuk mengunggah bukti transfer.
        </div>
      )}

      <PortalCard
        title="Pendaftaran terbaru"
        action={
          <Link className="lk" href="/portal/pendaftaran">
            Semua pendaftaran
          </Link>
        }
      >
        {regs.length ? (
          <div className="jt" style={{ marginTop: 16 }}>
            {regs.slice(0, 5).map((r) => (
              <RegistrationRow key={r.number} r={r} />
            ))}
          </div>
        ) : (
          <EmptyState text="Belum ada pendaftaran. Pilih jadwal training untuk memulai." href="/jadwal" cta="Lihat Jadwal Training" />
        )}
      </PortalCard>

      <PortalCard title="Lengkapi profil">
        <p className="fh" style={{ marginTop: 8 }}>
          Nama sesuai sertifikat, nomor WhatsApp, dan instansi dipakai untuk administrasi kelas dan sertifikat.{" "}
          <Link href="/portal/profil">Perbarui profil</Link>
        </p>
      </PortalCard>
    </>
  );
}
