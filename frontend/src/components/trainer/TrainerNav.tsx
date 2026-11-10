"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import Icon from "@/components/ui/Icon";
import type { IconName } from "@/components/ui/icons";
import { logoutAction } from "@/lib/actions/auth";

const ITEMS: { href: string; label: string; icon: IconName }[] = [
  { href: "/trainer", label: "Dashboard", icon: "chart" },
  { href: "/trainer/kelas", label: "Kelas Saya", icon: "grad" },
  { href: "/trainer/materi", label: "Materi & Progres", icon: "laptop" },
  { href: "/trainer/tugas", label: "Penilaian Tugas", icon: "code" },
  { href: "/trainer/kuis", label: "Hasil Kuis", icon: "trophy" },
  { href: "/trainer/profil", label: "Profil", icon: "users" },
  { href: "/portal", label: "Portal Member", icon: "arr" },
];

/** Navigasi samping portal trainer — memakai gaya .pnav yang sama dengan portal member. */
export default function TrainerNav({ name, title }: { name: string; title?: string | null }) {
  const path = usePathname();
  return (
    <aside className="pnav">
      <div className="pnav-user">
        <span className="av" aria-hidden="true">
          {name.slice(0, 1).toUpperCase()}
        </span>
        <div>
          <b>{name}</b>
          <small>{title ?? "Trainer"}</small>
        </div>
      </div>
      <nav>
        {ITEMS.map((it) => {
          const active = it.href === "/trainer" ? path === "/trainer" : path.startsWith(it.href);
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
