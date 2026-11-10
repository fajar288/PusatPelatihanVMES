import WhyCard from "@/components/cards/WhyCard";
import { WHY } from "@/data/home";

/** section.ms — "Mengapa PPTI" 6 kartu. */
export default function WhyUs() {
  return (
    <section className="ms">
      <div className="wrap">
        <div className="cn">
          <span className="eb">{WHY.eyebrow}</span>
          <h2 className="tt">{WHY.title}</h2>
          <p className="sbt">{WHY.sub}</p>
        </div>
        <div className="wg">
          {WHY.items.map((w, i) => (
            <WhyCard key={w.title} {...w} index={i + 1} />
          ))}
        </div>
      </div>
    </section>
  );
}
