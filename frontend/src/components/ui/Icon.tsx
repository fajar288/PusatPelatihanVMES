import type { IconName } from "./icons";

type Props = {
  name: IconName;
  /** class asli: "ic" (default), "ic lg2", "sr" (bintang), "qm" (quote) */
  className?: string;
  viewBox?: boolean;
};

/** <svg class="ic"><use href="#i-xxx"/></svg> — markup identik dengan tema asli. */
export default function Icon({ name, className = "ic", viewBox }: Props) {
  return (
    <svg className={className} viewBox={viewBox ? "0 0 24 24" : undefined}>
      <use href={`#i-${name}`} />
    </svg>
  );
}
