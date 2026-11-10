export type SortKey = "new" | "pop" | "lo" | "hi" | "az";

export type CatalogFilter = {
  q: string;
  category: string;
  mode: string;
  sort: SortKey;
};

type Props = {
  value: CatalogFilter;
  onChange: (next: CatalogFilter) => void;
  categories: string[];
  modes?: string[]; // kosong/undefined → select Mode disembunyikan (seperti halaman jadwal/artikel)
  sortOptions: { value: SortKey; label: string }[];
  count: number;
  label: string; // "training" | "postingan"
};

/**
 * div.catb — bar pencarian + filter + urutan katalog.
 * Port dari markup `#cat-bar` dan logika `draw()` di theme.main.js (state di komponen induk).
 */
export default function CatalogBar({ value, onChange, categories, modes, sortOptions, count, label }: Props) {
  const set = (patch: Partial<CatalogFilter>) => onChange({ ...value, ...patch });
  return (
    <div className="catb" id="cat-bar" data-kur={modes && modes.length ? "1" : "0"}>
      <div className="cats">
        {/* ikon cari inline (bukan sprite), persis seperti tema asli */}
        <svg className="ic" viewBox="0 0 24 24">
          <circle cx="11" cy="11" r="8" />
          <path d="m21 21-4.3-4.3" />
        </svg>
        <input
          aria-label="Cari"
          id="cat-q"
          placeholder="Cari training, vendor, atau topik..."
          type="search"
          value={value.q}
          onChange={(e) => set({ q: e.target.value })}
        />
      </div>
      <div className="catf">
        <select aria-label="Kategori" id="cat-k" value={value.category} onChange={(e) => set({ category: e.target.value })}>
          <option value="">Kategori: Semua</option>
          {categories.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
        <select
          aria-label="Mode"
          id="cat-m"
          value={value.mode}
          onChange={(e) => set({ mode: e.target.value })}
          style={modes && modes.length ? undefined : { display: "none" }}
        >
          <option value="">Mode: Semua</option>
          {(modes ?? []).map((m) => (
            <option key={m} value={m}>
              {m}
            </option>
          ))}
        </select>
        <select aria-label="Urutkan" id="cat-o" value={value.sort} onChange={(e) => set({ sort: e.target.value as SortKey })}>
          {sortOptions.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      </div>
      <div className="catn">
        <span id="cat-n">
          Menampilkan {count} {label}
        </span>
        <span>Data real dari katalog PPTI - VMES Training Center</span>
      </div>
    </div>
  );
}
