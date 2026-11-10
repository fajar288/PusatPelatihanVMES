import { ICON_SYMBOLS } from "./icons";

/** Sprite SVG inline — dipasang sekali di awal <body>, persis seperti website lama. */
export default function IconSprite() {
  return (
    <svg
      height="0"
      width="0"
      style={{ position: "absolute" }}
      aria-hidden="true"
      dangerouslySetInnerHTML={{ __html: ICON_SYMBOLS }}
    />
  );
}
