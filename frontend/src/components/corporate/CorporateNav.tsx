"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import Icon from "@/components/ui/Icon";
import type { IconName } from "@/components/ui/icons";

type Item = { href: string; label: string; icon: IconName };

/** Navigasi workspace perusahaan; menu mengikuti kemampuan peran (server tetap memeriksa ulang setiap permintaan). */
export default function CorporateNav({ org, name, role, unit, capabilities, multiOrg }: { org: string; name: string; role: string; unit: string | null; capabilities: string[]; multiOrg: boolean }) {
  const path = usePathname();
  const base = `/corporate/${org}`;
  const items: Item[] = [
    { href: base, label: "Ringkasan", icon: "chart" },
    { href: `${base}/permintaan`, label: "Permintaan Training", icon: "brief" },
    ...(capabilities.includes("view_progress") || capabilities.includes("manage_participants") ? [{ href: `${base}/peserta`, label: "Peserta & Progres", icon: "users" as IconName }] : []),
    { href: `${base}/sertifikat`, label: "Sertifikat", icon: "award" },
    ...(capabilities.includes("view_finance") ? [{ href: `${base}/keuangan`, label: "Keuangan", icon: "boxes" as IconName }] : []),
    ...(capabilities.includes("manage_users") ? [{ href: `${base}/pengguna`, label: "Pengguna Perusahaan", icon: "shield" as IconName }] : []),
  ];
  return (
    <aside className="pnav">
      <div className="pnav-user">
        <span className="av" aria-hidden="true">
          {name.slice(0, 1).toUpperCase()}
        </span>
        <div>
          <b>{name}</b>
          <small>
            {role}
            {unit ? ` · ${unit}` : ""}
          </small>
        </div>
      </div>
      <nav>
        {items.map((it) => {
          const active = it.href === base ? path === base : path.startsWith(it.href);
          return (
            <Link key={it.href} href={it.href} className={active ? "on" : undefined} aria-current={active ? "page" : undefined}>
              <Icon name={it.icon} />
              {it.label}
            </Link>
          );
        })}
        {multiOrg && (
          <Link href="/corporate">
            <Icon name="factory" />
            Ganti organisasi
          </Link>
        )}
        <Link href="/portal">
          <Icon name="arrl" />
          Portal Member
        </Link>
      </nav>
    </aside>
  );
}
