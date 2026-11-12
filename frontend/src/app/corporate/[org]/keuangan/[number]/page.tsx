import InvoiceDetail from "@/components/portal/InvoiceDetail";
import InvoiceProofForm from "@/components/portal/InvoiceProofForm";
import PaymentAccounts from "@/components/portal/PaymentAccounts";
import { BackLink, InvoicePill, PortalCard } from "@/components/portal/ui";
import { requireUser } from "@/lib/auth";
import { orgPath } from "@/lib/corporate";
import { corporateApi } from "@/lib/corporate-server";
import { getPaymentAccounts } from "@/lib/payment-accounts";
import type { InvoiceInfo } from "@/lib/portal-types";

export const instant = false;

/** /corporate/[org]/keuangan/[number] — rincian invoice perusahaan, riwayat transaksi, unggah bukti transfer. */
export default async function CorporateInvoicePage({ params }: PageProps<"/corporate/[org]/keuangan/[number]">) {
  const { org, number } = await params;
  const next = `/corporate/${org}/keuangan/${number}`;
  const { token, user } = await requireUser(next);
  const inv = (await corporateApi<{ data: InvoiceInfo }>(orgPath(org, `/invoices/${encodeURIComponent(number)}`), next)).data;
  const accounts = inv.payable ? await getPaymentAccounts(token) : [];

  return (
    <>
      <BackLink href={`/corporate/${org}/keuangan`}>Kembali ke Keuangan</BackLink>
      <PortalCard title={`Invoice ${inv.number}`} action={<InvoicePill invoice={inv} />}>
        <InvoiceDetail invoice={inv} pdfHref={`/corporate/${org}/keuangan/${inv.number}/pdf`} />
      </PortalCard>
      {inv.payable && (
        <section className="kbx pcard">
          <h2>Bayar &amp; Unggah Bukti</h2>
          <PaymentAccounts accounts={accounts} intro={`Transfer ke salah satu rekening resmi berikut dengan berita "${inv.number}":`} />
          <InvoiceProofForm scope={org} number={inv.number} outstanding={inv.outstanding} accounts={accounts} payer={user.name} replacing={inv.awaitingVerification} />
        </section>
      )}
      {(inv.overdue || inv.status === "expired") && (
        <p className="fh" style={{ marginTop: 12 }}>
          Invoice ini sudah lewat jatuh tempo. Hubungi tim VMES untuk memperpanjang tagihan.
        </p>
      )}
    </>
  );
}
