import type { Metadata } from "next";
import { Suspense } from "react";
import AuthShell from "@/components/auth/AuthShell";
import { ResetForm } from "@/components/auth/PasswordForms";
import { SITE } from "@/data/site";

export const metadata: Metadata = { title: `Atur Ulang Sandi | ${SITE.name}`, robots: { index: false } };

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

async function Body({ searchParams }: Pick<PageProps<"/reset-password">, "searchParams">) {
  const sp = await searchParams;
  return <ResetForm token={first(sp.token)} email={first(sp.email)} />;
}

/** /reset-password?token=&email= — tujuan tautan dari email reset (backend ResetPasswordNotification). */
export default function ResetPasswordPage({ searchParams }: PageProps<"/reset-password">) {
  return (
    <AuthShell title="Atur Ulang Sandi" subtitle="Buat sandi baru untuk akun Anda.">
      <Suspense fallback={<ResetForm token="" email="" />}>
        <Body searchParams={searchParams} />
      </Suspense>
    </AuthShell>
  );
}
