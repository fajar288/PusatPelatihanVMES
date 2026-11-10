import Image from "next/image";
import Link from "next/link";
import Icon from "@/components/ui/Icon";
import type { MenuData, SiteSettings } from "@/lib/cms-types";
import { waLink } from "@/lib/utils";

/** footer.ft — identik dengan website lama. */
export default function Footer({ site, columns }: { site: SiteSettings; columns: MenuData["footer"] }) {
  return (
    <footer className="ft">
      <div className="wrap fg">
        <div>
          <div className="fl">
            <Link className="logo" href="/">
              <Image alt="PPTI - VMES Training Center" className="lg" src={site.logo} width={site.logoSize.w} height={site.logoSize.h} sizes="120px" loading="lazy" />
              <span className="lt">{site.shortName}</span>
            </Link>
          </div>
          <p className="fd">{site.footerDescription}</p>
          <div className="so">
            {site.socials.map((s) => (
              <a key={s.icon} href={s.href} aria-label={s.label} target="_blank" rel="noopener">
                <Icon name={s.icon} />
              </a>
            ))}
          </div>
        </div>
        {columns.map((col) => (
          <div key={col.title}>
            <h4>{col.title}</h4>
            <ul>
              {col.links.map((l) => (
                <li key={l.label}>
                  {l.href === "wa" ? (
                    <a className="wa" href={waLink()} target="_blank" rel="noopener">
                      {l.label}
                    </a>
                  ) : (
                    <Link href={l.href}>{l.label}</Link>
                  )}
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      <div className="fb">
        <div className="wrap">
          <span>&copy; 2026 {site.company}. All rights reserved.</span>
          <span>{site.tagline}</span>
        </div>
      </div>
    </footer>
  );
}
