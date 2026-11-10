"use client";

import Image from "next/image";
import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import Icon from "@/components/ui/Icon";
import type { HeroData, StatItem } from "@/lib/cms-types";
import { waLink } from "@/lib/utils";
import { useReducedMotion } from "@/lib/useReducedMotion";
import StatsBar from "./StatsBar";

/** section.hero2 — hero beranda + slider fade 4500ms + glass cards + stats bar. Data dari API /home (fallback src/data). */
export default function Hero({ hero, stats }: { hero: HeroData; stats: StatItem[] }) {
  const [cur, setCur] = useState(0);
  const reduce = useReducedMotion();
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);
  const slides = hero.slides;
  const n = slides.length;

  const play = useCallback(() => {
    if (timer.current) clearInterval(timer.current);
    if (n > 1 && !reduce) timer.current = setInterval(() => setCur((c) => (c + 1) % n), 4500);
  }, [n, reduce]);

  useEffect(() => {
    play();
    return () => {
      if (timer.current) clearInterval(timer.current);
    };
  }, [play]);

  return (
    <section className="hero2">
      <div className="wrap hg">
        <div>
          <span className="pill">
            <Icon name="spark" />
            {hero.pill}
          </span>
          <h1>
            {hero.titleLine1}
            <br />
            <span className="gt">{hero.titleLine2}</span>
          </h1>
          <p className="lead">{hero.lead}</p>
          <div className="f4">
            {hero.features.map((f) => (
              <div key={f.label}>
                <span className="ib">
                  <Icon name={f.icon} />
                </span>
                <span>{f.label}</span>
              </div>
            ))}
          </div>
          <div className="hb">
            <Link className="b-o" href="/jadwal">
              Lihat Jadwal Training <Icon name="arr" />
            </Link>
            <a className="b-w wa" href={waLink()} target="_blank" rel="noopener">
              <Icon name="msg" />
              Konsultasi via WhatsApp
            </a>
          </div>
        </div>
        <div className="hv">
          <div className="im">
            <div className="hs" id="h-hero">
              {slides.map((s, i) => (
                <img
                  key={s.src}
                  referrerPolicy="no-referrer"
                  alt={s.alt ?? "Training PPTI"}
                  className={i === cur ? "on" : ""}
                  src={s.src}
                  fetchPriority={i === 0 ? "high" : "low"}
                  decoding="async"
                />
              ))}
            </div>
            <div className="hdt" id="h-dots">
              {slides.map((s, i) => (
                <button
                  key={s.src}
                  type="button"
                  aria-label={`Slide ${i + 1}`}
                  className={i === cur ? "on" : ""}
                  onClick={() => {
                    setCur(i);
                    play();
                  }}
                />
              ))}
            </div>
          </div>
          <div className="gl g1">
            <span className="ib">
              <Icon name={hero.glassStat.icon} />
            </span>
            <div>
              <b>{hero.glassStat.title}</b>
              <small>{hero.glassStat.sub}</small>
            </div>
          </div>
          <div className="gl g2">
            <b>Partner Resmi</b>
            <div className="lg">
              {hero.officialPartners.map((p) => (
                <div key={p.name}>
                  {p.w && p.h ? (
                    <Image alt={p.name} src={p.logo} width={p.w} height={p.h} sizes="130px" style={{ width: "100%", height: "auto" }} />
                  ) : (
                    <img alt={p.name} src={p.logo} loading="lazy" />
                  )}
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
      <StatsBar stats={stats} />
    </section>
  );
}
