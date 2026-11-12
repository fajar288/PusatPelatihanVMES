import CopyText from "@/components/portal/CopyText";
import type { PaymentAccountInfo } from "@/lib/portal-types";

/**
 * Rekening tujuan pembayaran manual (menu admin "Rekening pembayaran"): rekening bank / Virtual Account statis.
 * Tanpa data → kalimat umum seperti sebelumnya.
 */
export default function PaymentAccounts({ accounts, intro }: { accounts: PaymentAccountInfo[]; intro?: string }) {
  if (!accounts.length) {
    return (
      <p className="fh" style={{ marginTop: 6 }}>
        Transfer ke rekening resmi PT. PPTI - VMES Training Center sesuai rincian pada email tagihan.
      </p>
    );
  }
  return (
    <>
      {intro && (
        <p className="fh" style={{ marginTop: 6 }}>
          {intro}
        </p>
      )}
      <ul className="sp pacc">
        {accounts.map((a) => (
          <li key={a.id}>
            <span className="spl">
              <b>
                {a.bank} {a.type === "virtual_account" && <span className="jst ok">{a.typeLabel}</span>}
              </b>
              <small>a.n. {a.name}</small>
              {a.instructions && <small className="pacc-i">{a.instructions}</small>}
            </span>
            <b className="spv">
              <span className="pacc-n">{a.number}</span>
              <CopyText value={a.number} label="Salin nomor" />
            </b>
          </li>
        ))}
      </ul>
    </>
  );
}
