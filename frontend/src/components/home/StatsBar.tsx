"use client";

import { useEffect, useRef } from "react";
import Icon from "@/components/ui/Icon";
import { STATS } from "@/data/home";

/**
 * div.sb > div.sg — bar statistik dengan counter animasi (port "Counter statistik" theme.main.js):
 * 1800ms ease-out-cubic, mulai saat 40% elemen terlihat, format angka id-ID.
 */
export default function StatsBar() {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const root = ref.current;
    if (!root) return;
    const reduce = window.matchMedia?.("(prefers-reduced-motion:reduce)").matches;
    const els = Array.from(root.querySelectorAll<HTMLElement>("b[data-n]"));
    if (!els.length || reduce) return;

    const run = (e: HTMLElement) => {
      const n = +(e.getAttribute("data-n") || 0);
      const sf = e.getAttribute("data-s") || "";
      let t0: number | null = null;
      const st = (ts: number) => {
        if (t0 === null) t0 = ts;
        const r = Math.min(1, (ts - t0) / 1800);
        const v = Math.round(n * (1 - Math.pow(1 - r, 3)));
        e.textContent = v.toLocaleString("id-ID") + sf;
        if (r < 1) requestAnimationFrame(st);
      };
      requestAnimationFrame(st);
    };

    els.forEach((e) => (e.textContent = "0" + (e.getAttribute("data-s") || "")));
    if ("IntersectionObserver" in window) {
      const io = new IntersectionObserver(
        (en) => {
          en.forEach((x) => {
            if (x.isIntersecting) {
              run(x.target as HTMLElement);
              io.unobserve(x.target);
            }
          });
        },
        { threshold: 0.4 },
      );
      els.forEach((e) => io.observe(e));
      return () => io.disconnect();
    }
    els.forEach(run);
  }, []);

  return (
    <div className="sb">
      <div className="sg" ref={ref}>
        {STATS.map((s) => (
          <div key={s.label}>
            <span className="ib">
              <Icon name={s.icon} />
            </span>
            <div>
              <b data-n={s.n} data-s={s.suffix}>
                {s.n.toLocaleString("id-ID")}
                {s.suffix}
              </b>
              <small>{s.label}</small>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
