import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import AuthShell from "@/components/auth/AuthShell";
import LoginForm from "@/components/auth/LoginForm";
import { SITE } from "@/data/site";
import { getCurrentUser } from "@/lib/auth";

export const metadata: Metadata = { title: `Masuk | ${SITE.name}`, robots: { index: false } };

// Membaca cookie sesi & ?next= langsung (route diblokir di server) agar pengguna yang sudah login dapat redirect HTTP.
export const instant = false;

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

/** /login — masuk portal member (cookie sesi httpOnly via server action). */
export default async function LoginPage({ searchParams }: PageProps<"/login">) {
  const sp = await searchParams;
  const next = first(sp.next).startsWith("/") ? first(sp.next) : "/portal";
  if (await getCurrentUser()) redirect(next);
  return (
    <AuthShell
      title="Masuk Portal Member"
      subtitle="Pantau status pendaftaran, pembayaran, dan riwayat pelatihan Anda."
      footer={
        <>
          Belum punya akun? <Link href="/register">Buat akun</Link>
        </>
      }
    >
      <LoginForm next={next} />
    </AuthShell>
  );
}
