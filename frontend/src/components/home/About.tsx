import { ABOUT } from "@/data/home";
import { SITE } from "@/data/site";

/** section.tk#tentang-kami — video profil YouTube + teks tentang. */
export default function About() {
  return (
    <section className="tk" id="tentang-kami">
      <div className="wrap tkg">
        <div className="tkv">
          <iframe
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowFullScreen
            loading="lazy"
            referrerPolicy="strict-origin-when-cross-origin"
            src={`https://www.youtube.com/embed/${SITE.youtubeProfileId}`}
            title="Profil PPTI - VMES Training Center"
          />
        </div>
        <div className="tkt">
          <span className="eb">
            <i />
            {ABOUT.eyebrow}
          </span>
          <h2>
            {ABOUT.titlePrefix}
            <em>{ABOUT.titleEm}</em>
          </h2>
          {ABOUT.paragraphs.map((p) => (
            <p key={p.slice(0, 24)}>{p}</p>
          ))}
          <div className="tg">{ABOUT.tagline}</div>
        </div>
      </div>
    </section>
  );
}
