import Link from "next/link";
import NotificationSettingsForm from "@/components/portal/NotificationSettingsForm";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { openNotificationAction, readAllNotificationsAction } from "@/lib/actions/notifications";
import { api, requireUser } from "@/lib/auth";
import type { AppNotification, NotificationSettings } from "@/lib/portal-types";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

const fmt = (iso: string) => new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" });

const CATEGORY_LABEL: Record<string, string> = {
  registration: "Pendaftaran",
  schedule: "Jadwal",
  learning: "Belajar",
  certificate: "Sertifikat",
  announcement: "Pengumuman",
};

type ListResponse = { data: AppNotification[]; meta: { current_page: number; last_page: number; total: number; unread: number } };

/** /portal/notifikasi — notifikasi aplikasi (PRD §5.12): status baca, tautan ke halaman terkait, pengaturan email & push. */
export default async function PortalNotificationsPage({ searchParams }: PageProps<"/portal/notifikasi">) {
  const sp = await searchParams;
  const unreadOnly = sp.filter === "unread";
  const page = Math.max(1, Number(sp.page) || 1);
  const { token } = await requireUser("/portal/notifikasi");
  const [list, settings] = await Promise.all([
    api<ListResponse>(`/me/notifications?page=${page}${unreadOnly ? "&filter=unread" : ""}`, { token }),
    api<{ data: NotificationSettings }>("/me/notification-settings", { token }).then((r) => r.data),
  ]);
  const { data: items, meta } = list;
  const qs = (p: number) => `/portal/notifikasi?${unreadOnly ? "filter=unread&" : ""}page=${p}`;

  return (
    <>
      <PortalCard
        title="Notifikasi"
        action={
          meta.unread > 0 ? (
            <form action={readAllNotificationsAction}>
              <button type="submit" className="btn bl nbtn-sm">
                Tandai semua dibaca
              </button>
            </form>
          ) : undefined
        }
      >
        <nav className="ntabs" aria-label="Filter notifikasi">
          <Link href="/portal/notifikasi" className={!unreadOnly ? "on" : undefined} aria-current={!unreadOnly ? "page" : undefined}>
            Semua
          </Link>
          <Link href="/portal/notifikasi?filter=unread" className={unreadOnly ? "on" : undefined} aria-current={unreadOnly ? "page" : undefined}>
            Belum dibaca{meta.unread ? ` (${meta.unread})` : ""}
          </Link>
        </nav>
        {items.length ? (
          <ul className="pnotif">
            {items.map((n) => (
              <li key={n.id} className={n.read ? undefined : "unread"}>
                <form action={openNotificationAction}>
                  <input type="hidden" name="id" value={n.id} />
                  <input type="hidden" name="url" value={n.url ?? "/portal/notifikasi"} />
                  <button type="submit" className="nitem">
                    <span className="nmeta">
                      <span className="ncat">{CATEGORY_LABEL[n.category] ?? "Info"}</span>
                      <time dateTime={n.createdAt}>{fmt(n.createdAt)}</time>
                      {!n.read && <span className="ndot" aria-label="Belum dibaca" />}
                    </span>
                    <b>{n.title}</b>
                    {n.body && <p>{n.body}</p>}
                  </button>
                </form>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState text={unreadOnly ? "Tidak ada notifikasi yang belum dibaca." : "Belum ada notifikasi."} />
        )}
        {meta.last_page > 1 && (
          <div className="npg">
            {page > 1 ? <Link href={qs(page - 1)}>← Sebelumnya</Link> : <span />}
            <span>
              Halaman {page} dari {meta.last_page}
            </span>
            {page < meta.last_page ? <Link href={qs(page + 1)}>Berikutnya →</Link> : <span />}
          </div>
        )}
      </PortalCard>
      <PortalCard title="Pengaturan notifikasi">
        <NotificationSettingsForm settings={settings} />
      </PortalCard>
    </>
  );
}
