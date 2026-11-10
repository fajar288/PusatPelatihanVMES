import Icon from "@/components/ui/Icon";
import type { IconName } from "@/components/ui/icons";

type Props = { icon: IconName; title: string; desc: string; index: number };

/** div.wc — kartu "Mengapa PPTI" dengan nomor 01..06. */
export default function WhyCard({ icon, title, desc, index }: Props) {
  return (
    <div className="wc">
      <div className="wi">
        <Icon name={icon} />
      </div>
      <div className="wh">
        <h3>{title}</h3>
        <span>{String(index).padStart(2, "0")}</span>
      </div>
      <p>{desc}</p>
    </div>
  );
}
