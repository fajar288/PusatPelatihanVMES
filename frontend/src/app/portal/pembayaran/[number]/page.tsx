import { notFound } from "next/navigation";
import InvoiceDetail from "@/components/portal/InvoiceDetail";
import InvoiceProofForm from "@/components/portal/InvoiceProofForm";
import PaymentAccounts from "@/components/portal/PaymentAccounts";
import { BackLink, InvoicePill, PortalCard } from "@/components/portal/ui";
import { api, ApiRequestError, requireUser } from "@/lib/auth";
import { getPaymentAccounts } from "@/lib/payment-accounts";
import type { InvoiceInfo } from "@/lib/portal-types";

export const instant = false;

/** /portal/pembayaran/[number] — rincian invoice, riwayat transaksi, unggah bukti transfer. */
export default async function InvoicePage({ params }: PageProps<"/portal/pembayaran/[number]">) {
  const { number } = await params;
  const { token, user } = await requireUser(`/portal/pembayaran/${number}`);
  let inv: InvoiceInfo;
  try {
    inv = (await api<{ data: InvoiceInfo }>(`/me/invoices/${encodeURIComponent(number)}`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && e.error.status === 404) notFound();
    throw e;
  }
  const accounts = inv.payable ? await getPaymentAccounts(token) : [];

  return (
    <>
      <BackLink href="/portal/pembayaran">Kembali ke Invoice &amp; Pembayaran</BackLink>
      <PortalCard title={`Invoice ${inv.number}`} action={<InvoicePill invoice={inv} />}>
        <InvoiceDetail invoice={inv} pdfHref={`/portal/pembayaran/${inv.number}/pdf`} />
      </PortalCard>
      {inv.payable ? (
        <section className="kbx pcard">
          <h2>Bayar &amp; Unggah Bukti</h2>
          <PaymentAccounts accounts={accounts} intro={`Transfer ke salah satu rekening resmi berikut dengan berita "${inv.number}":`} />
          <InvoiceProofForm scope="me" number={inv.number} outstanding={inv.outstanding} accounts={accounts} payer={user.name} replacing={inv.awaitingVerification} />
        </section>
      ) : (
        (inv.overdue || inv.status === "expired") && (
          <p className="fh" style={{ marginTop: 12 }}>
            Invoice ini sudah lewat jatuh tempo. Hubungi tim VMES untuk memperpanjang tagihan.
          </p>
        )
      )}
    </>
  );
}
