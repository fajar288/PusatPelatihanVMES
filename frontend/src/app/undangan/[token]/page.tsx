import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import AuthShell, { FormMessage } from "@/components/auth/AuthShell";
import AcceptInvitationForm from "@/components/corporate/AcceptInvitationForm";
import { MetaList } from "@/components/portal/ui";
import { SITE } from "@/data/site";
import { api, ApiRequestError, getCurrentUser } from "@/lib/auth";

export const metadata: Metadata = { title: `Undangan Training | ${SITE.name}`, robots: { index: false } };

export const instant = false;

type Invitation = {
  status: "pending" | "accepted" | "revoked" | "expired";
  usable: boolean;
  emailHint: string;
  name: string | null;
  organization: string;
  program: string | null;
  schedule: { code: string; start: string | null; end: string | null; city: string | null } | null;
  targetDate: string | null;
  expiresAt: string | null;
};

/** /undangan/[token] — undangan peserta dari perusahaan (Roadmap 5 §20.4): masuk dengan email yang diundang, consent, terima. */
export default async function InvitationPage({ params }: PageProps<"/undangan/[token]">) {
  const { token } = await params;
  let inv: Invitation;
  try {
    inv = (await api<{ data: Invitation }>(`/invitations/${encodeURIComponent(token)}`)).data;
  } catch (e) {
    if (e instanceof ApiRequestError && e.error.status === 404) notFound();
    throw e;
  }
  const user = await getCurrentUser();
  const next = `/undangan/${token}`;

  return (
    <AuthShell
      title="Undangan Training"
      subtitle={
        <>
          <b>{inv.organization}</b> menugaskan {inv.name ? <b>{inv.name}</b> : "Anda"} mengikuti training di {SITE.name}.
        </>
      }
      footer={user ? <Link href="/portal">Buka Portal Member</Link> : undefined}
    >
      <MetaList
        items={[
          { icon: "grad", label: "Program", value: inv.program },
          !!inv.schedule && { icon: "cal", label: "Jadwal", value: `${inv.schedule.start} – ${inv.schedule.end}${inv.schedule.city ? `, ${inv.schedule.city}` : ""}` },
          !inv.schedule && !!inv.targetDate && { icon: "cal", label: "Target", value: inv.targetDate },
          { icon: "msg", label: "Untuk email", value: inv.emailHint },
          inv.usable && { label: "Berlaku sampai", value: inv.expiresAt },
        ]}
      />
      <div style={{ marginTop: 18 }}>
        {!inv.usable ? (
          <FormMessage ok={false} message={inv.status === "accepted" ? "Undangan ini sudah diterima." : "Undangan ini sudah tidak berlaku. Hubungi HR perusahaan Anda untuk undangan baru."} />
        ) : !user ? (
          <>
            <p className="fh">Masuk dengan akun yang memakai email undangan. Belum punya akun? Daftar dengan email tersebut — satu akun dipakai untuk seluruh histori training Anda.</p>
            <div className="ff" style={{ marginTop: 14 }}>
              <Link className="kb1" href={`/login?next=${encodeURIComponent(next)}`}>
                Masuk
              </Link>
              <Link className="kb2" href={`/register?next=${encodeURIComponent(next)}`}>
                Daftar akun baru
              </Link>
            </div>
          </>
        ) : (
          <AcceptInvitationForm token={token} userEmail={user.email} organization={inv.organization} />
        )}
      </div>
    </AuthShell>
  );
}
