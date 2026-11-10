import Link from "next/link";
import Icon from "@/components/ui/Icon";
import { FOOTER_COLUMNS, SITE, SOCIALS } from "@/data/site";
import { waLink } from "@/lib/utils";

/** footer.ft — identik dengan website lama. */
export default function Footer() {
  return (
    <footer className="ft">
      <div className="wrap fg">
        <div>
          <div className="fl">
            <Link className="logo" href="/">
              <img alt="PPTI - VMES Training Center" className="lg" src={SITE.logo} />
              <span className="lt">{SITE.shortName}</span>
            </Link>
          </div>
          <p className="fd">{SITE.footerDescription}</p>
          <div className="so">
            {SOCIALS.map((s) => (
              <a key={s.icon} href={s.href} aria-label={s.label} target="_blank" rel="noopener">
                <Icon name={s.icon} />
              </a>
            ))}
          </div>
        </div>
        {FOOTER_COLUMNS.map((col) => (
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
          <span>&copy; 2026 {SITE.company}. All rights reserved.</span>
          <span>{SITE.tagline}</span>
        </div>
      </div>
    </footer>
  );
}
