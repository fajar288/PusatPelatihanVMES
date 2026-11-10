import Icon from "@/components/ui/Icon";
import type { SiteSettings } from "@/lib/cms-types";
import { waLink } from "@/lib/utils";

/** section.ct — kartu Kantor Pusat & Telepon/WhatsApp, tampil di semua halaman (di luar <main>). */
export default function ContactCards({ site }: { site: SiteSettings }) {
  return (
    <section className="ct">
      <div className="wrap kcs">
        <a className="kc" href={site.mapsUrl} rel="noopener" target="_blank">
          <span className="ki">
            <Icon name="pin" />
          </span>
          <div>
            <b>Kantor Pusat</b>
            <em>{site.company}</em>
            <small>{site.address}</small>
          </div>
        </a>
        <a className="kc wa" href={waLink()} target="_blank" rel="noopener">
          <span className="ki">
            <Icon name="phone" />
          </span>
          <div>
            <b>Telepon / WhatsApp</b>
            <em>{site.waDisplay}</em>
            <small>Chat langsung dengan tim kami</small>
          </div>
        </a>
      </div>
    </section>
  );
}
