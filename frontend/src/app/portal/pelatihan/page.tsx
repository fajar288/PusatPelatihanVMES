import Link from "next/link";
import { EmptyState, PortalCard, StatusPill } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { RegistrationInfo } from "@/lib/portal-types";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal/pelatihan — My Training: kelas terkonfirmasi (akses materi/LMS menyusul Phase 3). */
export default async function PortalTrainingPage() {
  const { token } = await requireUser("/portal/pelatihan");
  const regs = (await api<{ data: RegistrationInfo[] }>("/me/registrations", { token })).data.filter((r) => r.status === "confirmed");
  return (
    <PortalCard title="Pelatihan Saya">
      {regs.length ? (
        <div className="kjl" style={{ marginTop: 16 }}>
          {regs.map((r) => (
            <div className="kj" key={r.number}>
              <div>
                <b>{r.schedule?.title}</b>
                <p>
                  {r.schedule?.dateLabel} • {r.schedule?.city} • {r.schedule?.mode} • Kode {r.schedule?.code}
                </p>
              </div>
              <div className="kjr">
                <StatusPill status={r.status} />
                {r.schedule?.packageSlug && (
                  <Link className="jb" href={`/kursus/${r.schedule.packageSlug}`}>
                    Detail program
                  </Link>
                )}
              </div>
            </div>
          ))}
        </div>
      ) : (
        <EmptyState text="Belum ada pelatihan terkonfirmasi. Pendaftaran yang sudah dikonfirmasi admin akan tampil di sini." href="/portal/pendaftaran" cta="Lihat Pendaftaran" />
      )}
      <p className="fh" style={{ marginTop: 18 }}>
        Materi belajar, kehadiran, tugas, dan sertifikat akan tersedia di sini setelah modul LMS (Phase 3) aktif.
      </p>
    </PortalCard>
  );
}
