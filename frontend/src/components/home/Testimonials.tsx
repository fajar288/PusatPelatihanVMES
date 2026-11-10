"use client";

import { useRef, useState } from "react";
import Icon from "@/components/ui/Icon";
import type { Testimonial } from "@/data/testimonials";

const AV = "/images/avatar-default.svg"; // website lama: az-icons.com/images/users/icon.svg

function Stars({ n }: { n: number }) {
  return (
    <div className="stars">
      {Array.from({ length: n }, (_, i) => (
        <Icon key={i} name="star" className="sr" viewBox />
      ))}
    </div>
  );
}

function Avatar({ t, cls }: { t: Testimonial; cls?: string }) {
  const u = t.image || AV;
  return <img referrerPolicy="no-referrer" className={[u === AV ? "dflt" : "", cls || ""].join(" ").trim()} alt={t.name} src={u} />;
}

/**
 * section.ts — testimoni: 1 featured (.tf) + daftar kartu (.tc) yang bisa diklik untuk menukar featured.
 * Port dari blok "Testimoni" theme.main.js.
 */
export default function Testimonials({ items }: { items: Testimonial[] }) {
  const [cur, setCur] = useState(0);
  const tl = useRef<HTMLDivElement>(null);
  if (!items.length) return null;
  const t = items[cur];

  const pick = (i: number) => {
    setCur(i);
    if (tl.current) tl.current.scrollLeft = 0;
  };
  const go = (d: number) => tl.current?.scrollBy({ left: d * tl.current.clientWidth * 0.8, behavior: "smooth" });

  return (
    <section className="ts">
      <div className="wrap">
        <div className="cn">
          <span className="eb">Testimoni</span>
          <h2 className="tt">Apa Kata Mereka Tentang Kami</h2>
          <p className="sbt">
            Ribuan alumni dan profesional telah merasakan manfaat training PPTI untuk meningkatkan skill, sertifikasi, dan
            karir di bidang IT.
          </p>
        </div>

        <div className="tf" id="h-tfeat">
          <div className="ph">
            <Avatar t={t} />
          </div>
          <div className="tb">
            <div>
              {t.program && <span className="tp">{t.program}</span>}
              <Stars n={t.stars} />
              <Icon name="quote" className="qm" viewBox />
              <p className="tq">“{t.quote}”</p>
            </div>
            <div className="au">
              <Avatar t={t} />
              <div>
                <b>{t.name}</b>
                <small>{t.role}</small>
              </div>
            </div>
          </div>
        </div>

        <div className="tw" style={{ display: items.length > 1 ? undefined : "none" }}>
          <button aria-label="Sebelumnya" className="ar l" type="button" onClick={() => go(-1)}>
            &#8592;
          </button>
          <div className="tl" id="h-tlist" ref={tl}>
            {items.map((x, i) =>
              i === cur ? null : (
                <div
                  className="tc"
                  key={x.name}
                  tabIndex={0}
                  onClick={() => pick(i)}
                  onKeyDown={(e) => e.key === "Enter" && pick(i)}
                >
                  {x.program && <span className="tp">{x.program}</span>}
                  <Stars n={x.stars} />
                  <p>“{x.quote}”</p>
                  <div className="au">
                    <Avatar t={x} />
                    <div>
                      <b>{x.name}</b>
                      <small>{x.role}</small>
                    </div>
                  </div>
                </div>
              ),
            )}
          </div>
          <button aria-label="Berikutnya" className="ar r" type="button" onClick={() => go(1)}>
            &#8594;
          </button>
        </div>
      </div>
    </section>
  );
}
