import type { Metadata } from "next";
import InstallPrompt from "@/components/pwa/InstallPrompt";
import PortalNav from "@/components/portal/PortalNav";
import VerifyEmailBanner from "@/components/portal/VerifyEmailBanner";
import { SITE } from "@/data/site";
import { requireUser } from "@/lib/auth";

export const metadata: Metadata = { title: `Portal Member | ${SITE.name}`, robots: { index: false } };

/**
 * Area member bersifat per-pengguna (cookie sesi) → route diblokir di server (instant = false, cacheComponents)
 * supaya pengguna tanpa sesi mendapat redirect HTTP sungguhan ke /login?next=, bukan redirect sisi klien.
 */
export const instant = false;

/** /portal/* — area member (PRD §5.6). */
export default async function PortalLayout({ children }: LayoutProps<"/portal">) {
  const { user } = await requireUser();
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
