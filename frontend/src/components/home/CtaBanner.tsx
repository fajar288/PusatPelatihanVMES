import Link from "next/link";
import Icon from "@/components/ui/Icon";
import type { CtaData } from "@/lib/cms-types";
import WaAnchor from "@/components/site/WaAnchor";

/** section.cs > .ctw: banner CTA hijau gelap dengan glass panel 4 poin. */
export default function CtaBanner({ cta }: { cta: CtaData }) {
  return (
    <section className="cs">
      <div className="wrap">
        <div className="ctw">
          <div className="ctg">
            <div>
              <span className="eb2">{cta.eyebrow}</span>
              <h2>{cta.title}</h2>
              <p className="cd">{cta.desc}</p>
              <div className="cbt">
                <Link className="c1" href="/jadwal">
                  Daftar Sekarang <Icon name="arr" />
                </Link>
                <WaAnchor className="c2 wa">
                  <Icon name="msg" />
                  Konsultasi via WhatsApp
                </WaAnchor>
              </div>
            </div>
            <div className="cpn">
              {cta.points.map((p) => (
                <div key={p}>
                  <span className="ib">
                    <Icon name="check" />
                  </span>
                  {p}
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
