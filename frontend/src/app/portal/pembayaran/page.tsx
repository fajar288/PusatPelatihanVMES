import Link from "next/link";
import PaymentAccounts from "@/components/portal/PaymentAccounts";
import { EmptyState, InvoicePill, MetaList, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import { getPaymentAccounts } from "@/lib/payment-accounts";
import type { InvoiceInfo } from "@/lib/portal-types";
import { formatRupiah } from "@/lib/utils";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

/** /portal/pembayaran — invoice & status verifikasi pembayaran (Payment History, Roadmap 5C). */
export default async function PortalPaymentsPage() {
  const { token } = await requireUser("/portal/pembayaran");
  const [invoices, accounts] = await Promise.all([
    api<{ data: InvoiceInfo[] }>("/me/payments", { token }).then((r) => r.data),
    getPaymentAccounts(token),
  ]);
  return (
    <PortalCard title="Invoice & Pembayaran">
      {invoices.length ? (
        <ul className="sp">
          {invoices.map((inv) => (
            <li key={inv.number}>
              <span className="spl">
                <b>
                  <Link href={`/portal/pembayaran/${inv.number}`}>{inv.number}</Link>
                </b>
                <MetaList
                  compact
                  items={[
                    !!inv.registration && { icon: "code", value: inv.registration },
                    inv.installmentTotal > 1 && { value: `Termin ${inv.installmentNo}/${inv.installmentTotal}` },
                    { icon: "cal", label: "Jatuh tempo", value: inv.dueLabel },
                  ]}
                />
              </span>
              <b className="spv">
                {formatRupiah(inv.total)} <InvoicePill invoice={inv} />
                {inv.payable && (
                  <small>
                    <Link href={`/portal/pembayaran/${inv.number}`}>Bayar &amp; unggah bukti</Link>
                  </small>
                )}
              </b>
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState text="Belum ada tagihan. Invoice dibuat setelah pendaftaran disetujui admin." />
      )}
      <div style={{ marginTop: 20 }}>
        <h3 style={{ fontSize: 17, color: "var(--ink)" }}>Rekening Pembayaran</h3>
        <PaymentAccounts accounts={accounts} intro="Bayar invoice dengan transfer ke salah satu rekening resmi berikut, cantumkan nomor invoice di berita transfer." />
        <p className="fh" style={{ marginTop: 10 }}>
          Setelah membayar, buka invoice lalu unggah bukti transfer. Status berubah menjadi lunas setelah diverifikasi tim finance.
        </p>
      </div>
    </PortalCard>
  );
}
