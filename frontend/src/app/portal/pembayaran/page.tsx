import Link from "next/link";
import { EmptyState, PaymentPill, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { PaymentInfo } from "@/lib/portal-types";
import { formatRupiah } from "@/lib/utils";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal/pembayaran — riwayat tagihan & status verifikasi (Payment History). */
export default async function PortalPaymentsPage() {
  const { token } = await requireUser("/portal/pembayaran");
  const payments = (await api<{ data: PaymentInfo[] }>("/me/payments", { token })).data;
  return (
    <PortalCard title="Riwayat Pembayaran">
      {payments.length ? (
        <ul className="sp">
          {payments.map((p) => (
            <li key={p.invoice}>
              <span>
                <b style={{ display: "block", color: "var(--ink)" }}>{p.invoice}</b>
                {p.registration && <Link href={`/portal/pendaftaran/${p.registration}`}>{p.registration}</Link>}
                {p.dueLabel ? ` • jatuh tempo ${p.dueLabel}` : ""}
                {p.note ? ` • ${p.note}` : ""}
              </span>
              <b>
                {formatRupiah(p.amount)} <PaymentPill status={p.status} />
              </b>
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState text="Belum ada tagihan. Tagihan dibuat setelah pendaftaran disetujui admin." />
      )}
      <p className="fh" style={{ marginTop: 16 }}>
        Pembayaran melalui transfer bank ke rekening resmi PT. PPTI - VMES Training Center. Unggah bukti transfer dari halaman detail pendaftaran.
      </p>
    </PortalCard>
  );
}
