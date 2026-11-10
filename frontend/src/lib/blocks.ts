import type { PostBlock } from "@/data/articles";

const esc = (s: string) => s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");

/**
 * PostBlock[] (format konten statis src/data) → HTML, sama persis dengan yang disimpan di kolom `body` CMS.
 * Dipakai oleh fallback frontend dan skrip ekspor seed (frontend/scripts/export-seed.mjs).
 */
export function blocksToHtml(blocks: PostBlock[]): string {
  return blocks
    .map((b) => {
      switch (b.type) {
        case "h2":
          return `<h2>${esc(b.text)}</h2>`;
        case "h3":
          return `<h3>${esc(b.text)}</h3>`;
        case "ul":
          return `<ul>${b.items.map((i) => `<li>${esc(i)}</li>`).join("")}</ul>`;
        case "ol":
          return `<ol>${b.items.map((i) => `<li>${esc(i)}</li>`).join("")}</ol>`;
        case "pre":
          return `<pre><code>${esc(b.code)}</code></pre>`;
        case "img":
          return `<figure><img src="${esc(b.src)}" alt="${esc(b.alt)}" loading="lazy" referrerpolicy="no-referrer">${b.caption ? `<figcaption>${esc(b.caption)}</figcaption>` : ""}</figure>`;
        default:
          return `<p>${esc(b.text)}</p>`;
      }
    })
    .join("\n");
}
