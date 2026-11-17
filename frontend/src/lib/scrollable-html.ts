/**
 * Isi HTML dari CMS/LMS (artikel, halaman statis, materi): tabel dibungkus wadah .xscroll dan blok <pre> diberi
 * tabindex/role/aria-label, supaya konten lebar digeser di dalam wadahnya (juga dengan keyboard) tanpa membuat
 * halaman ikut melebar.
 */
export function scrollableHtml(html: string): string {
  return html
    .replace(/<table\b/gi, '<div class="xscroll" tabindex="0" role="region" aria-label="Tabel, geser ke samping untuk melihat semua kolom"><table')
    .replace(/<\/table>/gi, "</table></div>")
    .replace(/<pre(?![^>]*\btabindex=)(\s[^>]*)?>/gi, (_m, attrs = "") => `<pre${attrs} tabindex="0" role="region" aria-label="Blok kode, geser ke samping bila terpotong">`);
}
