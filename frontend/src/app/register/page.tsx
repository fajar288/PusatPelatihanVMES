import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import AuthShell from "@/components/auth/AuthShell";
import RegisterAccountForm from "@/components/auth/RegisterAccountForm";
import { SITE } from "@/data/site";
import { getCurrentUser } from "@/lib/auth";

export const metadata: Metadata = { title: `Buat Akun | ${SITE.name}`, robots: { index: false } };

export const instant = false;

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

/** /register — buat akun member (satu akun = satu nomor member). */
export default async function RegisterPage({ searchParams }: PageProps<"/register">) {
  const sp = await searchParams;
  const next = first(sp.next).startsWith("/") ? first(sp.next) : "/portal";
  if (await getCurrentUser()) redirect(next);
  return (
    <AuthShell
      title="Buat Akun Member"
      subtitle="Satu akun untuk semua pelatihan: pendaftaran, pembayaran, materi, dan sertifikat."
      footer={
        <>
          Sudah punya akun? <Link href="/login">Masuk</Link>
        </>
      }
    >
      <RegisterAccountForm next={next} />
    </AuthShell>
  );
}
