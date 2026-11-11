import Link from "next/link";
import { PAYMENT_PILL, type PaymentInfo, type RegistrationInfo, STATUS_PILL } from "@/lib/portal-types";
import Icon from "@/components/ui/Icon";
import type { IconName } from "@/components/ui/icons";
import { formatRupiah } from "@/lib/utils";

export function StatusPill({ status }: { status: RegistrationInfo["status"] }) {
  const p = STATUS_PILL[status];
  return <span className={"jst " + p.cls}>{p.label}</span>;
}

export function PaymentPill({ status }: { status: PaymentInfo["status"] }) {
  const p = PAYMENT_PILL[status];
  return <span className={"jst " + p.cls}>{p.label}</span>;
}

/** Satu butir metadata: ikon opsional, label opsional (abu-abu), nilai (tebal). */
export type MetaEntry = { icon?: IconName; label?: string; value: React.ReactNode };

/**
 * Daftar metadata yang membungkus per butir (tiap butir tidak terpotong di tengah),
 * pengganti baris teks panjang yang disambung "•". `compact` untuk baris di dalam daftar/kartu kecil.
 * Butir bernilai kosong (null/undefined/"") otomatis dilewati.
 */
export function MetaList({ items, compact, style }: { items: (MetaEntry | false | null | undefined)[]; compact?: boolean; style?: React.CSSProperties }) {
  const list = items.filter((m): m is MetaEntry => !!m && m.value !== null && m.value !== undefined && m.value !== "");
  if (!list.length) return null;
  return (
    <ul className={"pmeta" + (compact ? " sm" : "")} style={style}>
      {list.map((m, i) => (
        <li key={i} className="pmi">
          {m.icon && <Icon name={m.icon} />}
          {m.label && <span className="pmeta-l">{m.label}</span>}
          <span className="pmeta-v">{m.value}</span>
        </li>
      ))}
    </ul>
  );
}

/** Tombol kembali di atas konten portal/trainer: "← Kembali ke …" bergaya pil, ikon panah tema. */
export function BackLink({ href, children }: { href: string; children: React.ReactNode }) {
  return (
    <Link className="bk pbk" href={href}>
      <span className="pbk-ic" aria-hidden="true">
        <Icon name="arrl" />
      </span>
      <span>{children}</span>
    </Link>
  );
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
        <MetaList
          compact
          items={[
            { icon: "code", value: r.number },
            { icon: "pin", value: r.schedule?.city },
            { icon: "monitor", value: r.schedule?.mode },
            { icon: "users", value: `${r.participantsCount} peserta` },
          ]}
        />
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
