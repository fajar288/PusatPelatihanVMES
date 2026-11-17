"use client";

import { useSite } from "@/components/site/SiteProvider";
import { waLink } from "@/lib/utils";

type Props = Omit<React.AnchorHTMLAttributes<HTMLAnchorElement>, "href"> & {
  /** Teks prefill; kosong = pesan pembuka dari pengaturan situs. */
  text?: string;
};

/** Tautan WhatsApp dengan nomor dari pengaturan CMS; bisa dipakai di komponen server maupun klien. */
export default function WaAnchor({ text, children, target = "_blank", rel = "noopener", ...rest }: Props) {
  const site = useSite();
  return (
    <a href={waLink(site, text)} target={target} rel={rel} {...rest}>
      {children}
    </a>
  );
}
