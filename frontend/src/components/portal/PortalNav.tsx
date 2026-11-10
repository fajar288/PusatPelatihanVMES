"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import Icon from "@/components/ui/Icon";
import type { IconName } from "@/components/ui/icons";
import { logoutAction } from "@/lib/actions/auth";

const ITEMS: { href: string; label: string; icon: IconName }[] = [
  { href: "/portal", label: "Dashboard", icon: "chart" },
  { href: "/portal/profil", label: "Profil", icon: "users" },
  { href: "/portal/pelatihan", label: "Pelatihan Saya", icon: "grad" },
  { href: "/portal/pendaftaran", label: "Riwayat Pendaftaran", icon: "brief" },
  { href: "/portal/pembayaran", label: "Riwayat Pembayaran", icon: "award" },
  { href: "/portal/notifikasi", label: "Notifikasi", icon: "msg" },
  { href: "/portal/bantuan", label: "Bantuan", icon: "phone" },
];

/** Navigasi samping portal member (PRD §5.6) dengan tautan aktif + tombol keluar. */
export default function PortalNav({ name, memberNumber, isTrainer }: { name: string; memberNumber?: string | null; isTrainer?: boolean }) {
  const path = usePathname();
  return (
    <aside className="pnav">
      <div className="pnav-user">
        <span className="av" aria-hidden="true">
          {name.slice(0, 1).toUpperCase()}
        </span>
        <div>
          <b>{name}</b>
          <small>{memberNumber ?? "Member"}</small>
        </div>
      </div>
      <nav>
        {[...ITEMS, ...(isTrainer ? [{ href: "/trainer", label: "Portal Trainer", icon: "grad" as IconName }] : [])].map((it) => {
          const active = it.href === "/portal" ? path === "/portal" : path.startsWith(it.href);
          return (
            <Link key={it.href} href={it.href} className={active ? "on" : undefined} aria-current={active ? "page" : undefined}>
              <Icon name={it.icon} />
              {it.label}
            </Link>
          );
        })}
      </nav>
      <form action={logoutAction}>
        <button type="submit" className="pnav-out">
          <Icon name="arrl" />
          Keluar
        </button>
      </form>
    </aside>
  );
}
