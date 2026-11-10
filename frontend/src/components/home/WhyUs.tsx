import WhyCard from "@/components/cards/WhyCard";
import type { WhyData } from "@/lib/cms-types";

/** section.ms — "Mengapa PPTI" 6 kartu. */
export default function WhyUs({ why }: { why: WhyData }) {
  return (
    <section className="ms">
      <div className="wrap">
        <div className="cn">
          <span className="eb">{why.eyebrow}</span>
          <h2 className="tt">{why.title}</h2>
          <p className="sbt">{why.sub}</p>
        </div>
        <div className="wg">
          {why.items.map((w, i) => (
            <WhyCard key={w.title} {...w} index={i + 1} />
          ))}
        </div>
      </div>
    </section>
  );
}
