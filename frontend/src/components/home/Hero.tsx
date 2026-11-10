"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import Icon from "@/components/ui/Icon";
import { HERO, HERO_SLIDES } from "@/data/home";
import { waLink } from "@/lib/utils";
import { useReducedMotion } from "@/lib/useReducedMotion";
import StatsBar from "./StatsBar";

/** section.hero2 — hero beranda + slider fade 4500ms + glass cards + stats bar. */
export default function Hero() {
  const [cur, setCur] = useState(0);
  const reduce = useReducedMotion();
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);
  const n = HERO_SLIDES.length;

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
            {HERO.pill}
          </span>
          <h1>
            {HERO.titleLine1}
            <br />
            <span className="gt">{HERO.titleLine2}</span>
          </h1>
          <p className="lead">{HERO.lead}</p>
          <div className="f4">
            {HERO.features.map((f) => (
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
              {HERO_SLIDES.map((src, i) => (
                <img
                  key={src}
                  referrerPolicy="no-referrer"
                  alt="Training PPTI"
                  className={i === cur ? "on" : ""}
                  src={src}
                  fetchPriority={i === 0 ? "high" : undefined}
                />
              ))}
            </div>
            <div className="hdt" id="h-dots">
              {HERO_SLIDES.map((src, i) => (
                <button
                  key={src}
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
              <Icon name={HERO.glassStat.icon} />
            </span>
            <div>
              <b>{HERO.glassStat.title}</b>
              <small>{HERO.glassStat.sub}</small>
            </div>
          </div>
          <div className="gl g2">
            <b>Partner Resmi</b>
            <div className="lg">
              {HERO.officialPartners.map((p) => (
                <div key={p.name}>
                  <img alt={p.name} loading="lazy" src={p.logo} />
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
      <StatsBar />
    </section>
  );
}
