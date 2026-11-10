import { EmptyState, PortalCard, RegistrationRow } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { RegistrationInfo } from "@/lib/portal-types";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal/pendaftaran — riwayat semua pendaftaran (Registration History). */
export default async function PortalRegistrationsPage() {
  const { token } = await requireUser("/portal/pendaftaran");
  const regs = (await api<{ data: RegistrationInfo[] }>("/me/registrations", { token })).data;
  return (
    <PortalCard title="Riwayat Pendaftaran">
      {regs.length ? (
        <div className="jt" style={{ marginTop: 16 }}>
          {regs.map((r) => (
            <RegistrationRow key={r.number} r={r} />
          ))}
        </div>
      ) : (
        <EmptyState text="Belum ada pendaftaran." href="/jadwal" cta="Lihat Jadwal Training" />
      )}
    </PortalCard>
  );
}
