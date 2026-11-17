/**
 * Wadah untuk konten yang bisa lebih lebar dari layar (tabel, daftar lebar). Konten digeser menyamping di dalam
 * wadah ini, halaman tidak ikut melebar. tabIndex + role="region" + aria-label: bisa difokus dan digeser dengan
 * tombol panah keyboard, dan pembaca layar menyebut nama wilayahnya. Gaya: .xscroll di styles/extra.css.
 */
export function ScrollX({ label, children, style }: { label: string; children: React.ReactNode; style?: React.CSSProperties }) {
  return (
    <div className="xscroll" tabIndex={0} role="region" aria-label={label} style={style}>
      {children}
    </div>
  );
}
