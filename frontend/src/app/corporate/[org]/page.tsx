import Link from "next/link";
import { PortalCard } from "@/components/portal/ui";
import { can, REQUEST_PILL, type RequestStatus } from "@/lib/corporate";
import { getSummary } from "@/lib/corporate-server";

export const instant = false;

const STATUS_LABEL: Record<RequestStatus, string> = {
  draft: "Draf",
  submitted: "Dikirim ke VMES",
  quoted: "Penawaran dibuat",
  approval: "Menunggu persetujuan",
  approved: "Disetujui",
  paid: "Pembayaran diterima",
  scheduled: "Terjadwal",
  in_progress: "Berjalan",
  completed: "Selesai",
  cancelled: "Dibatalkan",
};

/** /corporate/[org] — ringkasan: peserta bersponsor, training aktif, completion, kehadiran, sertifikat, kursi, permintaan. */
export default async function CorporateOverview({ params }: PageProps<"/corporate/[org]">) {
  const { org } = await params;
  const s = await getSummary(org);
  const st = s.stats;
  const util = st.seatsBought > 0 ? Math.round((st.seatsFilled / st.seatsBought) * 100) : null;
  const tiles: { label: string; value: string; hint?: string }[] = [
    { label: "Peserta disponsori", value: String(st.participants), hint: `${st.activeEnrollments} training aktif` },
    { label: "Completion", value: st.completionRate === null ? "-" : `${st.completionRate}%`, hint: `${st.completed} selesai` },
    { label: "Rata-rata kehadiran", value: st.attendanceAvg === null ? "-" : `${st.attendanceAvg}%`, hint: "kelas yang sudah berjalan" },
    { label: "Sertifikat terbit", value: String(st.certificates) },
    { label: "Kursi terisi", value: util === null ? "-" : `${util}%`, hint: `${st.seatsFilled}/${st.seatsBought} kursi` },
  ];
  if (st.activeEmployees !== null) tiles.push({ label: "Karyawan aktif", value: String(st.activeEmployees), hint: "terdaftar di VMES" });
  const reqs = Object.entries(st.requests) as [RequestStatus, number][];

  return (
    <>
      <PortalCard
        title={s.organization.name}
        action={
          can(s, "manage_requests") ? (
            <Link className="lk" href={`/corporate/${org}/permintaan/baru`}>
              + Permintaan training
            </Link>
          ) : undefined
        }
      >
        <p className="fh">
          Data yang tampil hanya training yang <b>disponsori {s.organization.name}</b>. Training mandiri karyawan atau yang disponsori perusahaan lain tidak terlihat di sini.
        </p>
        <div className="kic pstats" style={{ marginTop: 16 }}>
          {tiles.map((t) => (
            <div className="kic1" key={t.label}>
              <small>{t.label}</small>
              <b>{t.value}</b>
              {t.hint && <small style={{ textTransform: "none", letterSpacing: 0 }}>{t.hint}</small>}
            </div>
          ))}
        </div>
      </PortalCard>

      <PortalCard
        title="Permintaan training"
        action={
          <Link className="lk" href={`/corporate/${org}/permintaan`}>
            Lihat semua
          </Link>
        }
      >
        {reqs.length === 0 ? (
          <p className="fh">
            Belum ada permintaan. Ajukan kebutuhan training berdasarkan jumlah kursi — nama peserta bisa diisi kemudian.
          </p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {reqs.map(([k, n]) => (
              <span key={k} className={`jst ${REQUEST_PILL[k]}`}>
                {STATUS_LABEL[k] ?? k}: {n}
              </span>
            ))}
          </div>
        )}
        {s.organization.requiresApproval && (
          <p className="fh" style={{ marginTop: 12, fontSize: 13 }}>
            Organisasi ini mewajibkan persetujuan Corporate Owner sebelum penawaran disetujui.
          </p>
        )}
      </PortalCard>
    </>
  );
}
