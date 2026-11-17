import type { Metadata } from "next";
import InstallPrompt from "@/components/pwa/InstallPrompt";
import PortalNav from "@/components/portal/PortalNav";
import VerifyEmailBanner from "@/components/portal/VerifyEmailBanner";
import { api, requireUser } from "@/lib/auth";
import type { AssistantStatus } from "@/lib/portal-types";

export const metadata: Metadata = { title: "Portal Member", robots: { index: false } };

/**
 * Area member bersifat per-pengguna (cookie sesi) → route diblokir di server (instant = false, cacheComponents)
 * supaya pengguna tanpa sesi mendapat redirect HTTP sungguhan ke /login?next=, bukan redirect sisi klien.
 */
export const instant = false;

/** /portal/*: area member (PRD §5.6). */
export default async function PortalLayout({ children }: LayoutProps<"/portal">) {
  const { user, token } = await requireUser();
  // Lencana "Belum tersedia" di menu Asisten Belajar hanya tampil saat fitur mati (gagal dicek = dianggap mati).
  const assistantAvailable = await api<{ data: AssistantStatus }>("/me/assistant?brief=1", { token })
    .then((r) => r.data.available)
    .catch(() => false);
  return (
    <>
      <section className="ph0">
        <div className="wrap">
          <h1>Portal Member</h1>
          <p>Pendaftaran, pembayaran, pelatihan, materi belajar, tugas, hasil, dan profil Anda dalam satu akun.</p>
        </div>
      </section>
      <div className="wrap pgrid">
        <PortalNav
          name={user.name}
          memberNumber={user.member?.memberNumber}
          isTrainer={user.roles.some((r) => r === "trainer" || r === "assessor")}
          hasCorporate={(user.corporate?.length ?? 0) > 0}
          unread={user.unreadNotifications ?? 0}
          assistantAvailable={assistantAvailable}
        />
        <section className="pmain">
          {user.emailVerified === false && <VerifyEmailBanner email={user.email} />}
          <InstallPrompt />
          {children}
        </section>
      </div>
      <div style={{ height: 60 }} />
    </>
  );
}
