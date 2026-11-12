import Link from "next/link";
import { notFound } from "next/navigation";
import RegistrationActions from "@/components/portal/RegistrationActions";
import { BackLink, InvoicePill, MetaList, PortalCard, StatusPill } from "@/components/portal/ui";
import { api, ApiRequestError, requireUser } from "@/lib/auth";
import { getPaymentAccounts } from "@/lib/payment-accounts";
import type { RegistrationInfo } from "@/lib/portal-types";
import { formatRupiah } from "@/lib/utils";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

const STEPS: { key: RegistrationInfo["status"]; label: string }[] = [
  { key: "pending", label: "Diterima" },
  { key: "under_review", label: "Ditinjau" },
  { key: "waiting_payment", label: "Pembayaran" },
  { key: "payment_verification", label: "Verifikasi" },
  { key: "confirmed", label: "Terkonfirmasi" },
];

/** /portal/pendaftaran/[number] — detail pendaftaran: progres status, rincian, tagihan, aksi pendaftar. */
export default async function PortalRegistrationDetail({ params }: PageProps<"/portal/pendaftaran/[number]">) {
  const { number } = await params;
  const { token, user } = await requireUser(`/portal/pendaftaran/${number}`);
  let r: RegistrationInfo;
  try {
    r = (await api<{ data: RegistrationInfo }>(`/me/registrations/${encodeURIComponent(number)}`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    throw e;
  }
  const stepIndex = STEPS.findIndex((s) => s.key === r.status);
  const final = r.status === "rejected" || r.status === "cancelled";
  const accounts = r.status === "waiting_payment" || r.status === "payment_verification" ? await getPaymentAccounts(token) : [];

  return (
    <>
      <BackLink href="/portal/pendaftaran">Kembali ke Riwayat pendaftaran</BackLink>
      <PortalCard title={`Pendaftaran ${r.number}`} action={<StatusPill status={r.status} />}>
        {!final && (
          <ol className="psteps" aria-label="Progres pendaftaran">
            {STEPS.map((s, i) => (
              <li key={s.key} className={i < stepIndex ? "done" : i === stepIndex ? "cur" : ""}>
                <span>{i + 1}</span>
                {s.label}
              </li>
            ))}
          </ol>
        )}
        {r.statusNote && (
          <div className="fok" style={{ marginTop: 16 }}>
            Catatan admin: {r.statusNote}
          </div>
        )}
        <ul className="sp">
          <li>
            <span>Program</span>
            <b>{r.schedule?.packageSlug ? <Link href={`/kursus/${r.schedule.packageSlug}`}>{r.schedule.title}</Link> : r.schedule?.title}</b>
          </li>
          <li>
            <span>Kelas</span>
            <b className="spv">
              {r.schedule?.code}
              <small>
                {r.schedule?.dateLabel}, {r.schedule?.city} ({r.schedule?.mode})
              </small>
            </b>
          </li>
          <li>
            <span>Jenis</span>
            <b>{r.type === "corporate" ? `Kolektif (${r.participantsCount} peserta)` : "Individu"}</b>
          </li>
          <li>
            <span>Pendaftar</span>
            <b className="spv">
              {r.name}
              <small>{r.email}</small>
              <small>{r.phone}</small>
            </b>
          </li>
          {r.company && (
            <li>
              <span>Instansi</span>
              <b className="spv">
                {r.company}
                {r.position && <small>{r.position}</small>}
              </b>
            </li>
          )}
          <li>
            <span>Estimasi biaya</span>
            <b>{r.total != null ? formatRupiah(r.total) : "Menunggu penawaran"}</b>
          </li>
          <li>
            <span>Tanggal daftar</span>
            <b>{r.createdLabel}</b>
          </li>
          {r.notes && (
            <li>
              <span>Catatan</span>
              <b>{r.notes}</b>
            </li>
          )}
        </ul>
        {r.participants && r.participants.length > 0 && (
          <>
            <h3 style={{ marginTop: 18, fontSize: 16 }}>Peserta</h3>
            <ul className="sp">
              {r.participants.map((p, i) => (
                <li key={i}>
                  <span>{p.name}</span>
                  <b className="spv">
                    {p.email || "-"}
                    {p.position && <small>{p.position}</small>}
                  </b>
                </li>
              ))}
            </ul>
          </>
        )}
      </PortalCard>

      {r.invoices && r.invoices.length > 0 && (
        <PortalCard title="Invoice & Pembayaran">
          <ul className="sp">
            {r.invoices.map((inv) => (
              <li key={inv.number}>
                <span className="spl">
                  <b>
                    <Link href={`/portal/pembayaran/${inv.number}`}>{inv.number}</Link>
                  </b>
                  <MetaList
                    compact
                    items={[
                      inv.installmentTotal > 1 && { value: `Termin ${inv.installmentNo}/${inv.installmentTotal}` },
                      { icon: "cal", label: "Jatuh tempo", value: inv.dueLabel },
                    ]}
                  />
                </span>
                <b className="spv">
                  {formatRupiah(inv.total)} <InvoicePill invoice={inv} />
                </b>
              </li>
            ))}
          </ul>
        </PortalCard>
      )}

      <RegistrationActions r={r} accounts={accounts} payer={user.name} />
    </>
  );
}
