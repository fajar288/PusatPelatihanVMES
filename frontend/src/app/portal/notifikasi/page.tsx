import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import { type AuditEvent, describeEvent } from "@/lib/portal-types";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

const fmt = (iso: string) => new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" });

/** /portal/notifikasi — peristiwa akun (pendaftaran, status, pembayaran, profil). Email tetap dikirim untuk tiap perubahan status. */
export default async function PortalNotificationsPage() {
  const { token } = await requireUser("/portal/notifikasi");
  const events = (await api<{ data: AuditEvent[] }>("/me/notifications", { token })).data;
  return (
    <PortalCard title="Notifikasi">
      {events.length ? (
        <ul className="pnotif">
          {events.map((e) => (
            <li key={e.id}>
              <time dateTime={e.at}>{fmt(e.at)}</time>
              <p>{describeEvent(e)}</p>
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState text="Belum ada notifikasi." />
      )}
    </PortalCard>
  );
}
