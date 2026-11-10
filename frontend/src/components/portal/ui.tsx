import Link from "next/link";
import { PAYMENT_PILL, type PaymentInfo, type RegistrationInfo, STATUS_PILL } from "@/lib/portal-types";
import { formatRupiah } from "@/lib/utils";

export function StatusPill({ status }: { status: RegistrationInfo["status"] }) {
  const p = STATUS_PILL[status];
  return <span className={"jst " + p.cls}>{p.label}</span>;
}

export function PaymentPill({ status }: { status: PaymentInfo["status"] }) {
  const p = PAYMENT_PILL[status];
  return <span className={"jst " + p.cls}>{p.label}</span>;
}

export function PortalCard({ title, children, action }: { title: string; children: React.ReactNode; action?: React.ReactNode }) {
  return (
    <section className="kbx pcard">
      <div className="pcard-h">
        <h2>{title}</h2>
        {action}
      </div>
      {children}
    </section>
  );
}

export function EmptyState({ text, href, cta }: { text: string; href?: string; cta?: string }) {
  return (
    <div className="nores" style={{ padding: "32px 0" }}>
      <p>{text}</p>
      {href && cta && (
        <Link className="btn" href={href}>
          {cta}
        </Link>
      )}
    </div>
  );
}

/** Baris pendaftaran (list) — memakai gaya baris jadwal .jr tema. */
export function RegistrationRow({ r }: { r: RegistrationInfo }) {
  return (
    <div className="jr prow">
      <div className="j1">
        <Link href={`/portal/pendaftaran/${r.number}`}>
          <h3>{r.schedule?.title ?? "Pendaftaran"}</h3>
        </Link>
        <p>
          {r.number} • {r.schedule?.city} • {r.schedule?.mode} • {r.participantsCount} peserta
        </p>
      </div>
      <div className="j2">
        <b>{r.schedule?.dateLabel}</b>
        <span>Didaftarkan {r.createdLabel}</span>
      </div>
      <div className="j3">
        <StatusPill status={r.status} />
        <b>{r.total != null ? formatRupiah(r.total) : "-"}</b>
      </div>
      <Link className="jb" href={`/portal/pendaftaran/${r.number}`}>
        Detail
      </Link>
    </div>
  );
}
