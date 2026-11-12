import type { Metadata } from "next";
import Link from "next/link";
import AuthShell, { FormMessage } from "@/components/auth/AuthShell";
import { SITE } from "@/data/site";
import { api, ApiRequestError } from "@/lib/auth";

export const metadata: Metadata = { title: `Verifikasi Email | ${SITE.name}`, robots: { index: false } };

// Tautan sekali pakai dari email → selalu diproses di server per permintaan (tidak di-prerender).
export const instant = false;

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

/** /verifikasi-email?id&hash&expires&signature — meneruskan tautan bertanda tangan ke API (Roadmap 5B). */
export default async function VerifyEmailPage({ searchParams }: PageProps<"/verifikasi-email">) {
  const sp = await searchParams;
  const [id, hash, expires, signature] = ["id", "hash", "expires", "signature"].map((k) => first(sp[k]));
  let ok = false;
  let message = "Tautan verifikasi tidak lengkap. Buka tautan dari email terbaru kami.";
  if (id && hash && expires && signature) {
    try {
      const r = await api<{ message: string }>(`/auth/email/verify/${encodeURIComponent(id)}/${encodeURIComponent(hash)}?expires=${encodeURIComponent(expires)}&signature=${encodeURIComponent(signature)}`);
      ok = true;
      message = r.message;
    } catch (e) {
      message =
        e instanceof ApiRequestError && e.error.status === 403
          ? "Tautan verifikasi tidak valid atau sudah kedaluwarsa. Masuk ke Portal Member lalu kirim ulang tautan verifikasi."
          : e instanceof ApiRequestError
            ? e.error.message
            : "Tidak dapat terhubung ke server. Coba lagi beberapa saat.";
    }
  }
  return (
    <AuthShell
      title={ok ? "Email Terverifikasi" : "Verifikasi Email"}
      subtitle={ok ? "Terima kasih. Histori training Anda kini tersambung ke satu akun, termasuk training yang didaftarkan perusahaan." : undefined}
      footer={<Link href="/portal">Buka Portal Member</Link>}
    >
      <FormMessage ok={ok} message={message} />
    </AuthShell>
  );
}
