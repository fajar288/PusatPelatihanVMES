import Link from "next/link";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { EnrollmentInfo } from "@/lib/portal-types";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

const STATUS: Record<EnrollmentInfo["status"], { label: string; cls: "ok" | "wn" | "no" }> = {
  enrolled: { label: "Terdaftar", cls: "ok" },
  attending: { label: "Mengikuti", cls: "wn" },
  completed: { label: "Selesai", cls: "ok" },
  failed: { label: "Tidak lulus", cls: "no" },
  dropped: { label: "Mengundurkan diri", cls: "no" },
};

const ATT: Record<string, "ok" | "wn" | "no"> = { present: "ok", late: "wn", excused: "wn", absent: "no" };

/** /portal/pelatihan — My Training: enrollment dari pendaftaran terkonfirmasi, trainer, sesi, kehadiran (GET /me/enrollments). */
export default async function PortalTrainingPage() {
  const { token } = await requireUser("/portal/pelatihan");
  const items = (await api<{ data: EnrollmentInfo[] }>("/me/enrollments", { token })).data;
  return (
    <>
      <PortalCard title="Pelatihan Saya">
        {items.length === 0 && (
          <EmptyState text="Belum ada pelatihan terkonfirmasi. Pendaftaran yang sudah dikonfirmasi admin akan tampil di sini." href="/portal/pendaftaran" cta="Lihat Pendaftaran" />
        )}
        {items.map((e) => (
          <div className="kbx" key={e.id} style={{ marginTop: 18, padding: 22 }}>
            <div className="pcard-h">
              <h3 style={{ fontSize: 18 }}>{e.schedule?.title}</h3>
              <span className={"jst " + STATUS[e.status].cls}>{STATUS[e.status].label}</span>
            </div>
            <p className="fh" style={{ marginTop: 6 }}>
              Kode {e.schedule?.code} • {e.schedule?.dateLabel} • {e.schedule?.city} • {e.schedule?.mode}
              {e.schedule?.time ? ` • ${e.schedule.time}` : ""}
              {e.schedule?.trainers.length ? ` • Trainer: ${e.schedule.trainers.map((t) => t.name).join(", ")}` : ""}
            </p>
            <div className="kic" style={{ marginTop: 14 }}>
              <div className="kic1">
                <small>Kehadiran</small>
                <b>{e.attendancePercent === null ? "-" : `${e.attendancePercent}%`}</b>
              </div>
              <div className="kic1">
                <small>Sesi</small>
                <b>{e.sessions.length}</b>
              </div>
              <div className="kic1">
                <small>Nilai akhir</small>
                <b>{e.finalScore ?? "-"}</b>
              </div>
            </div>
            {e.sessions.length > 0 && (
              <ul className="sp" style={{ marginBottom: 0 }}>
                {e.sessions.map((s) => (
                  <li key={s.no}>
                    <span>
                      Sesi {s.no} • {s.dateLabel}
                      {s.time ? ` • ${s.time}` : ""}
                      {s.topic ? ` • ${s.topic}` : ""}
                    </span>
                    <b>{s.attendance ? <span className={"jst " + (ATT[s.attendance] ?? "wn")}>{s.attendanceLabel}</span> : <span className="fh">belum dicatat</span>}</b>
                  </li>
                ))}
              </ul>
            )}
            {e.schedule?.packageSlug && (
              <p className="fh" style={{ marginTop: 12 }}>
                <Link href={`/kursus/${e.schedule.packageSlug}`}>Lihat detail program</Link>
              </p>
            )}
          </div>
        ))}
      </PortalCard>
      <p className="fh" style={{ marginTop: 18 }}>
        Materi belajar, tugas, dan sertifikat akan tersedia setelah modul LMS (Phase 3) aktif.
      </p>
    </>
  );
}
