"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { NAV, SITE } from "@/data/site";
import { waLink } from "@/lib/utils";

/**
 * Header sticky — port dari header.hd + JS fitNav/onSc di theme.main.js:
 *  - scroll > 24px → class "shr" (tinggi 56px, logo 36px, shadow)
 *  - nav overflow → "ns" (sembunyikan search), masih overflow → "cp" (hamburger + dropdown)
 */
export default function Header() {
  const hdRef = useRef<HTMLElement>(null);
  const wrapRef = useRef<HTMLDivElement>(null);
  const [shr, setShr] = useState(false);
  const [mode, setMode] = useState<"" | "ns" | "ns cp">("");
  const [open, setOpen] = useState(false);

  useEffect(() => {
    const hd = hdRef.current;
    const hw = wrapRef.current;
    if (!hd || !hw) return;

    const fitNav = () => {
      hd.classList.remove("ns", "cp");
      if (hw.scrollWidth <= hw.clientWidth + 1) {
        setMode("");
        return;
      }
      hd.classList.add("ns");
      if (hw.scrollWidth <= hw.clientWidth + 1) {
        setMode("ns");
        return;
      }
      hd.classList.add("cp");
      setMode("ns cp");
    };
    const onSc = () => {
      const y = window.pageYOffset || document.documentElement.scrollTop;
      setShr(y > 24);
    };
    window.addEventListener("scroll", onSc, { passive: true });
    window.addEventListener("resize", fitNav);
    window.addEventListener("load", fitNav);
    if (document.fonts?.ready) document.fonts.ready.then(fitNav);
    fitNav();
    onSc();
    return () => {
      window.removeEventListener("scroll", onSc);
      window.removeEventListener("resize", fitNav);
      window.removeEventListener("load", fitNav);
    };
  }, []);

  const cls = ["hd", shr ? "shr" : "", mode].filter(Boolean).join(" ");

  return (
    <header className={cls} id="hd" ref={hdRef}>
      <div className="wrap" ref={wrapRef}>
        <Link className="logo" href="/">
          <Image alt="PPTI - VMES Training Center" className="lg" src={SITE.logo} width={SITE.logoSize.w} height={SITE.logoSize.h} sizes="120px" priority />
          <span className="lt">{SITE.shortName}</span>
        </Link>
        <button aria-label="Menu" className="mb" id="mb" type="button" onClick={() => setOpen((o) => !o)}>
          &#9776;
        </button>
        <nav className={"nav" + (open ? " open" : "")} id="nav">
          {NAV.map((n) => (
            <Link key={n.href} href={n.href} onClick={() => setOpen(false)}>
              {n.label}
            </Link>
          ))}
          <a className="cta-n wa" href={waLink()} target="_blank" rel="noopener">
            Hubungi Kami
          </a>
          <form action="/search" className="nsf" method="get" role="search">
            <input aria-label="Cari" name="q" placeholder="Cari kursus atau artikel" type="search" defaultValue="" />
          </form>
        </nav>
        <form action="/search" className="sf" method="get" role="search">
          <input aria-label="Cari" name="q" placeholder="Cari kursus atau artikel" type="search" defaultValue="" />
        </form>
      </div>
    </header>
  );
}
