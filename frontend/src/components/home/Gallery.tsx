"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { GALLERY } from "@/data/home";

/** section.gs — mosaic galeri 6 foto + lightbox .lb (Esc / klik untuk tutup). */
export default function Gallery() {
  const [lb, setLb] = useState<string | null>(null);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setLb(null);
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, []);

  return (
    <>
      <section className="gs">
        <div className="wrap gk">
          <div>
            <span className="eb">Dokumentasi</span>
            <h2 className="tt">Momen Training &amp; Aktivitas Peserta PPTI</h2>
            <p className="sbt">
              Lihat suasana kelas, sesi praktik lab, hingga dokumentasi training korporat bersama instruktur PPTI.
            </p>
            <Link className="btn" href="/dokumentasi" style={{ marginTop: 24, borderRadius: 99, padding: "13px 26px" }}>
              Lihat galeri lengkap &#8594;
            </Link>
          </div>
          <div className="gm" id="h-gal">
            {GALLERY.map((g) => (
              <button key={g.src} type="button" aria-label={g.alt} onClick={() => setLb(g.src)}>
                <img referrerPolicy="no-referrer" loading="lazy" alt={g.alt} src={g.src} />
              </button>
            ))}
          </div>
        </div>
      </section>
      <div className={"lb" + (lb ? " on" : "")} id="lb" onClick={() => setLb(null)}>
        {lb && <img alt="" referrerPolicy="no-referrer" src={lb} />}
      </div>
    </>
  );
}
