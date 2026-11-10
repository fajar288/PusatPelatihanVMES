import Icon from "@/components/ui/Icon";
import { SITE } from "@/data/site";
import { waLink } from "@/lib/utils";

/** section.ct — kartu Kantor Pusat & Telepon/WhatsApp, tampil di semua halaman (di luar <main>). */
export default function ContactCards() {
  return (
    <section className="ct">
      <div className="wrap kcs">
        <a className="kc" href={SITE.mapsUrl} rel="noopener" target="_blank">
          <span className="ki">
            <Icon name="pin" />
          </span>
          <div>
            <b>Kantor Pusat</b>
            <em>{SITE.company}</em>
            <small>{SITE.address}</small>
          </div>
        </a>
        <a className="kc wa" href={waLink()} target="_blank" rel="noopener">
          <span className="ki">
            <Icon name="phone" />
          </span>
          <div>
            <b>Telepon / WhatsApp</b>
            <em>{SITE.waDisplay}</em>
            <small>Chat langsung dengan tim kami</small>
          </div>
        </a>
      </div>
    </section>
  );
}
