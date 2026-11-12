import Link from "next/link";
import { EmptyState, InvoicePill, MetaList, PortalCard } from "@/components/portal/ui";
import { type CorporateInvoiceList, orgPath, rupiah } from "@/lib/corporate";
import { corporateApi } from "@/lib/corporate-server";

export const instant = false;

/** /corporate/[org]/keuangan — invoice perusahaan (training request & pendaftaran kolektif), peran finance/owner. */
export default async function FinancePage({ params }: PageProps<"/corporate/[org]/keuangan">) {
  const { org } = await params;
  const { data: rows, summary } = await corporateApi<CorporateInvoiceList>(orgPath(org, "/invoices"), `/corporate/${org}/keuangan`);

  const tiles = [
    { label: "Belum dibayar", value: rupiah(summary.outstanding), hint: `${summary.openCount} invoice terbuka` },
    { label: "Menunggu verifikasi", value: String(summary.awaiting), hint: "bukti sedang dicek VMES" },
    { label: "Sudah dibayar", value: rupiah(summary.paid), hint: "terverifikasi, bersih refund" },
  ];

  return (
      <PortalCard title="Invoice">
        <div className="kic pstats" style={{ marginBottom: 16 }}>
          {tiles.map((t) => (
            <div className="kic1" key={t.label}>
              <small>{t.label}</small>
              <b>{t.value}</b>
              <small style={{ textTransform: "none", letterSpacing: 0 }}>{t.hint}</small>
            </div>
          ))}
        </div>
        {rows.length === 0 ? (
          <EmptyState text="Belum ada invoice. Invoice dibuat VMES setelah penawaran permintaan training disetujui." />
        ) : (
          <ul className="sp">
            {rows.map((inv) => (
              <li key={inv.number}>
                <span className="spl">
                  <b>
                    <Link href={`/corporate/${org}/keuangan/${inv.number}`}>{inv.number}</Link>
                  </b>
                  <MetaList
                    compact
                    items={[
                      { icon: "code", value: inv.trainingRequest ?? inv.registration },
                      inv.installmentTotal > 1 && { value: `Termin ${inv.installmentNo}/${inv.installmentTotal}` },
                      { icon: "cal", label: "Jatuh tempo", value: inv.dueLabel },
                    ]}
                  />
                </span>
                <b className="spv">
                  {rupiah(inv.total)} <InvoicePill invoice={inv} />
                  {inv.payable && (
                    <small>
                      <Link href={`/corporate/${org}/keuangan/${inv.number}`}>Unggah bukti transfer</Link>
                    </small>
                  )}
                </b>
              </li>
            ))}
          </ul>
        )}
        <p className="fh" style={{ marginTop: 14 }}>
          Pembayaran manual: transfer ke rekening resmi VMES, lalu unggah bukti di halaman invoice. Status menjadi lunas setelah diverifikasi tim finance VMES.
        </p>
      </PortalCard>
  );
}
