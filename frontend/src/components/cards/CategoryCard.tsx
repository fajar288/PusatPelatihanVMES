import Link from "next/link";
import Icon from "@/components/ui/Icon";
import type { IconName } from "@/components/ui/icons";

type Props = { icon: IconName; title: string; desc: string; href: string };

/** a.cat — kartu kategori kompetensi. */
export default function CategoryCard({ icon, title, desc, href }: Props) {
  return (
    <Link className="cat" href={href}>
      <span className="ib">
        <Icon name={icon} />
      </span>
      <span>
        <b>{title}</b>
        <small>{desc}</small>
      </span>
    </Link>
  );
}
