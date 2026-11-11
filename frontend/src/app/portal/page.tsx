import Link from "next/link";
import { EmptyState, PortalCard, RegistrationRow } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import { type CertificateInfo, MEMBER_STATUS } from "@/lib/certificates";
import type { EnrollmentInfo, RegistrationInfo } from "@/lib/portal-types";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal — dashboard: identitas member & status alumni, ringkasan pendaftaran, sertifikat, pendaftaran terbaru (PRD §5.6). */
export default async function PortalDashboard() {
  const { user, token } = await requireUser("/portal");
  const [regs, certs, enrollments] = await Promise.all([
    api<{ data: RegistrationInfo[] }>("/me/registrations", { token }).then((r) => r.data),
    api<{ data: CertificateInfo[] }>("/me/certificates", { token }).then((r) => r.data).catch(() => [] as CertificateInfo[]),
    api<{ data: EnrollmentInfo[] }>("/me/enrollments", { token }).then((r) => r.data).catch(() => [] as EnrollmentInfo[]),
  ]);
  const m = user.member;
  const counts = {
    active: regs.filter((r) => r.status === "confirmed").length,
    process: regs.filter((r) => ["pending", "under_review", "waiting_payment", "payment_verification"].includes(r.status)).length,
    pay: regs.filter((r) => r.status === "waiting_payment").length,
    completed: enrollments.filter((e) => e.status === "completed").length,
    certs: certs.filter((c) => c.isValid).length,
  };
  const isAlumni = m?.status === "alumni";

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
        <div className="kic1">
          <small>Pelatihan selesai</small>
          <b>{counts.completed}</b>
        </div>
        <div className="kic1">
          <small>Sertifikat berlaku</small>
          <b>{counts.certs}</b>
        </div>
      </div>

      {isAlumni && (
        <PortalCard
          title="Dashboard Alumni"
          action={
            <Link className="lk" href="/portal/sertifikat">
              Sertifikat saya
            </Link>
          }
        >
          <p className="fh" style={{ marginTop: 8 }}>
            Anda tercatat sebagai alumni PPTI - VMES. Unduh sertifikat di menu Sertifikat, lihat histori kelas & nilai di Pelatihan Saya dan Progres Saya, dan daftar program lanjutan
            dengan akun yang sama; riwayat lama tetap tersimpan.
          </p>
          <div className="cert-actions">
            <Link className="btn" href="/jadwal">
              Daftar program baru
            </Link>
            <Link className="btn sec" href="/portal/pelatihan">
              Histori pelatihan
            </Link>
            <Link className="btn sec" href="/portal/profil">
              Perbarui profil
            </Link>
          </div>
        </PortalCard>
      )}

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
