"use client";

import { useRef } from "react";
import { PARTNERS } from "@/data/home";

/** section.ps — logo partner grid 2 baris scroll horizontal, panah geser 80% lebar (fungsi slide() theme.main.js). */
export default function Partners() {
  const pt = useRef<HTMLDivElement>(null);
  const go = (d: number) => pt.current?.scrollBy({ left: d * pt.current.clientWidth * 0.8, behavior: "smooth" });

  return (
    <section className="ps">
      <div className="wrap">
        <div className="cn">
          <span className="eb">Partner &amp; Client</span>
          <h2 className="tt">Dipercaya oleh Perusahaan &amp; Instansi Terkemuka</h2>
        </div>
        <div className="pw">
          <button aria-label="Sebelumnya" className="ar l" type="button" onClick={() => go(-1)}>
            &#8592;
          </button>
          <div className="pt" ref={pt}>
            <div className="pgr" id="h-partner">
              {PARTNERS.map((p) => (
                <div className="lc" key={p.name}>
                  <img loading="lazy" alt={p.name} src={p.logo} />
                </div>
              ))}
            </div>
          </div>
          <button aria-label="Berikutnya" className="ar r" type="button" onClick={() => go(1)}>
            &#8594;
          </button>
        </div>
      </div>
    </section>
  );
}
