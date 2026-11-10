import type { ReactNode } from "react";

type Props = {
  eyebrow: string;
  title: ReactNode; // boleh mengandung <em> untuk teks gradien
  desc: string;
};

/** section.cath — hero halaman kategori (katalog kursus, jadwal, tutorial, dst.). Markup identik dengan tema asli. */
export default function CatalogHero({ eyebrow, title, desc }: Props) {
  return (
    <section className="cath">
      <div className="wrap">
        <span className="catc" id="cat-c">
          <i />
          {eyebrow}
        </span>
        <h1 id="cat-t">{title}</h1>
        <p id="cat-d">{desc}</p>
      </div>
    </section>
  );
}
