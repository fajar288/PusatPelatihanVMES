import Link from "next/link";
import PaymentAccounts from "@/components/portal/PaymentAccounts";
import { EmptyState, MetaList, PaymentPill, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import { getPaymentAccounts } from "@/lib/payment-accounts";
import type { PaymentInfo } from "@/lib/portal-types";
import { formatRupiah } from "@/lib/utils";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal/pembayaran — riwayat tagihan & status verifikasi (Payment History). */
export default async function PortalPaymentsPage() {
  const { token } = await requireUser("/portal/pembayaran");
  const [payments, accounts] = await Promise.all([
    api<{ data: PaymentInfo[] }>("/me/payments", { token }).then((r) => r.data),
    getPaymentAccounts(token),
  ]);
  return (
    <PortalCard title="Riwayat Pembayaran">
      {payments.length ? (
        <ul className="sp">
          {payments.map((p) => (
            <li key={p.invoice}>
              <span>
                <b style={{ display: "block", color: "var(--ink)" }}>{p.invoice}</b>
                <MetaList
                  compact
                  style={{ marginTop: 2 }}
                  items={[
                    !!p.registration && { icon: "code", value: <Link href={`/portal/pendaftaran/${p.registration}`}>{p.registration}</Link> },
                    { icon: "cal", label: "Jatuh tempo", value: p.dueLabel },
                    { value: p.note },
                  ]}
                />
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
      <div style={{ marginTop: 20 }}>
        <h3 style={{ fontSize: 17, color: "var(--ink)" }}>Rekening Pembayaran</h3>
        <PaymentAccounts accounts={accounts} intro="Bayar tagihan dengan transfer ke salah satu rekening resmi berikut." />
        <p className="fh" style={{ marginTop: 10 }}>
          Setelah membayar, unggah bukti transfer dari halaman detail pendaftaran. Tim finance akan memverifikasinya.
        </p>
      </div>
    </PortalCard>
  );
}
