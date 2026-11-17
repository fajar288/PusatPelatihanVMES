import type { Metadata } from "next";
import Link from "next/link";
import AuthShell from "@/components/auth/AuthShell";
import { ForgotForm } from "@/components/auth/PasswordForms";

export const metadata: Metadata = { title: "Lupa Sandi", robots: { index: false } };

export default function LupaPasswordPage() {
  return (
    <AuthShell
      title="Lupa Sandi"
      subtitle="Masukkan email akun Anda. Kami akan mengirim tautan untuk membuat sandi baru."
      footer={
        <>
          Ingat sandinya? <Link href="/login">Masuk</Link>
        </>
      }
    >
      <ForgotForm />
    </AuthShell>
  );
}
