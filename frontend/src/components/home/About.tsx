import type { AboutData } from "@/lib/cms-types";

/** section.tk#tentang-kami: video profil YouTube + teks tentang. */
export default function About({ about, youtubeId }: { about: AboutData; youtubeId: string }) {
  return (
    <section className="tk" id="tentang-kami">
      <div className="wrap tkg">
        <div className="tkv">
          <iframe
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowFullScreen
            loading="lazy"
            referrerPolicy="strict-origin-when-cross-origin"
            src={`https://www.youtube.com/embed/${youtubeId}`}
            title="Profil PPTI - VMES Training Center"
          />
        </div>
        <div className="tkt">
          {/* titik hijau berasal dari .eb:before (tema), sama seperti label .eb lain di beranda */}
          <span className="eb">{about.eyebrow}</span>
          <h2>
            {about.titlePrefix}
            <em>{about.titleEm}</em>
          </h2>
          {about.paragraphs.map((p, i) => (
            <p key={i}>{p}</p>
          ))}
          <div className="tg">{about.tagline}</div>
        </div>
      </div>
    </section>
  );
}
