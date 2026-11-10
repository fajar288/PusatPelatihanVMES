import Link from "next/link";
import { notFound } from "next/navigation";
import RegistrationActions from "@/components/portal/RegistrationActions";
import { PaymentPill, PortalCard, StatusPill } from "@/components/portal/ui";
import { api, ApiRequestError, requireUser } from "@/lib/auth";
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
  const { token } = await requireUser(`/portal/pendaftaran/${number}`);
  let r: RegistrationInfo;
  try {
    r = (await api<{ data: RegistrationInfo }>(`/me/registrations/${encodeURIComponent(number)}`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    throw e;
  }
  const stepIndex = STEPS.findIndex((s) => s.key === r.status);
  const final = r.status === "rejected" || r.status === "cancelled";

  return (
    <>
      <Link className="bk" href="/portal/pendaftaran">
        ← Riwayat pendaftaran
      </Link>
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
            <b>
              {r.schedule?.code} • {r.schedule?.dateLabel} • {r.schedule?.city} • {r.schedule?.mode}
            </b>
          </li>
          <li>
            <span>Jenis</span>
            <b>{r.type === "corporate" ? `Kolektif (${r.participantsCount} peserta)` : "Individu"}</b>
          </li>
          <li>
            <span>Pendaftar</span>
            <b>
              {r.name} • {r.email} • {r.phone}
            </b>
          </li>
          {r.company && (
            <li>
              <span>Instansi</span>
              <b>
                {r.company}
                {r.position ? ` • ${r.position}` : ""}
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
                  <b>{[p.email, p.position].filter(Boolean).join(" • ") || "-"}</b>
                </li>
              ))}
            </ul>
          </>
        )}
      </PortalCard>

      {r.payments && r.payments.length > 0 && (
        <PortalCard title="Tagihan & Pembayaran">
          <ul className="sp">
            {r.payments.map((p) => (
              <li key={p.invoice}>
                <span>
                  {p.invoice}
                  {p.dueLabel ? ` • jatuh tempo ${p.dueLabel}` : ""}
                </span>
                <b>
                  {formatRupiah(p.amount)} <PaymentPill status={p.status} />
                </b>
              </li>
            ))}
          </ul>
        </PortalCard>
      )}

      <RegistrationActions r={r} />
    </>
  );
}
