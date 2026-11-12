import { InvoicePill, MetaList } from "@/components/portal/ui";
import type { InvoiceInfo } from "@/lib/portal-types";
import { formatRupiah } from "@/lib/utils";

const TX_PILL: Record<string, "ok" | "wn" | "no"> = { paid: "ok", pending: "wn", failed: "no", cancelled: "no" };

/**
 * Rincian invoice (Roadmap 5C) untuk portal member & portal perusahaan: item, subtotal/diskon/pajak/total (baris pajak
 * disembunyikan bila tarif 0), pembayaran & riwayat transaksi. Tanpa tombol — aksi unggah ada di InvoiceProofForm.
 */
export default function InvoiceDetail({ invoice: inv, pdfHref }: { invoice: InvoiceInfo; pdfHref?: string }) {
  const items = inv.items ?? [];
  const txs = inv.transactions ?? [];
  const refunds = inv.refunds ?? [];
  const open = inv.status === "pending" || inv.status === "failed" || inv.status === "expired";

  return (
    <>
      <MetaList
        items={[
          { icon: "code", label: "Referensi", value: inv.source },
          { icon: "cal", label: "Terbit", value: inv.issuedLabel },
          { icon: "clock", label: "Jatuh tempo", value: inv.dueLabel ? `${inv.dueLabel}${inv.overdue ? " (lewat)" : ""}` : null },
          inv.installmentTotal > 1 && { icon: "boxes", label: "Termin", value: `${inv.installmentNo} dari ${inv.installmentTotal}` },
          !!inv.paidLabel && { icon: "check", label: "Lunas", value: inv.paidLabel },
        ]}
      />
      <p className="fh" style={{ marginTop: 10 }}>
        <b style={{ color: "var(--ink)" }}>Ditagihkan kepada:</b> {inv.billTo.name}
        {inv.billTo.company && inv.billTo.company !== inv.billTo.name ? ` · ${inv.billTo.company}` : ""}
        {inv.billTo.taxId ? ` · NPWP ${inv.billTo.taxId}` : ""}
      </p>

      <h3 style={{ marginTop: 18, fontSize: 16 }}>Rincian</h3>
      <ul className="sp">
        {items.map((it, i) => (
          <li key={i}>
            <span className="spl">
              <b>{it.description}</b>
              <small>
                {it.quantity} × {formatRupiah(it.unitPrice)}
              </small>
            </span>
            <b>{formatRupiah(it.amount)}</b>
          </li>
        ))}
      </ul>
      <ul className="sp inv-tot">
        <li>
          <span>Subtotal</span>
          <b>{formatRupiah(inv.subtotal)}</b>
        </li>
        {inv.discount > 0 && (
          <li>
            <span>Diskon{inv.discountNote ? ` (${inv.discountNote})` : ""}</span>
            <b>− {formatRupiah(inv.discount)}</b>
          </li>
        )}
        {inv.showTax && (
          <li>
            <span>
              {inv.taxLabel ?? "Pajak"} {inv.taxRate.toLocaleString("id-ID")}%
            </span>
            <b>{formatRupiah(inv.taxAmount)}</b>
          </li>
        )}
        <li className="grand">
          <span>Total</span>
          <b>{formatRupiah(inv.total)}</b>
        </li>
        {inv.amountPaid > 0 && (
          <li>
            <span>Terbayar (terverifikasi)</span>
            <b>{formatRupiah(inv.amountPaid)}</b>
          </li>
        )}
        {inv.amountRefunded > 0 && (
          <li>
            <span>Dikembalikan</span>
            <b>{formatRupiah(inv.amountRefunded)}</b>
          </li>
        )}
        {open && (
          <li>
            <span>Sisa tagihan</span>
            <b>
              {formatRupiah(inv.outstanding)} <InvoicePill invoice={inv} />
            </b>
          </li>
        )}
      </ul>
      {inv.settlementNote && <p className="inv-note">{inv.settlementNote}</p>}
      {inv.notes && <p className="inv-note">{inv.notes}</p>}

      {txs.length > 0 && (
        <>
          <h3 style={{ marginTop: 18, fontSize: 16 }}>Riwayat Pembayaran</h3>
          <ul className="sp">
            {txs.map((t) => (
              <li key={t.code}>
                <span className="spl">
                  <b>{t.code}</b>
                  <small>
                    {[t.method, t.transferLabel && `transfer ${t.transferLabel}`, t.destination].filter(Boolean).join(" · ")}
                  </small>
                  {t.reviewNote && <small style={{ color: "#b42318" }}>Alasan ditolak: {t.reviewNote}</small>}
                </span>
                <b className="spv">
                  {formatRupiah(t.amountReceived ?? t.amount)} <span className={"jst " + (TX_PILL[t.status] ?? "wn")}>{t.statusLabel}</span>
                </b>
              </li>
            ))}
          </ul>
        </>
      )}

      {refunds.length > 0 && (
        <>
          <h3 style={{ marginTop: 18, fontSize: 16 }}>Pengembalian Dana</h3>
          <ul className="sp">
            {refunds.map((r) => (
              <li key={r.code}>
                <span className="spl">
                  <b>{r.code}</b>
                  {r.reference && (
                    <small>
                      Ref. {r.reference}
                      {r.completedLabel ? ` · ${r.completedLabel}` : ""}
                    </small>
                  )}
                </span>
                <b className="spv">
                  {formatRupiah(r.amount)} <span className={"jst " + (r.status === "completed" ? "ok" : "wn")}>{r.statusLabel}</span>
                </b>
              </li>
            ))}
          </ul>
        </>
      )}

      {pdfHref && (
        <p style={{ marginTop: 16 }}>
          <a className="lk" href={pdfHref} target="_blank" rel="noopener">
            Unduh invoice (PDF)
          </a>
        </p>
      )}
    </>
  );
}
