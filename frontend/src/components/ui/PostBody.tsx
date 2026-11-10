import type { PostBlock } from "@/data/articles";

/** Render blok konten (pengganti HTML WordPress) ke dalam div.pb. */
export default function PostBody({ blocks, className = "pb" }: { blocks: PostBlock[]; className?: string }) {
  return (
    <div className={className}>
      {blocks.map((b, i) => {
        switch (b.type) {
          case "h2":
            return <h2 key={i}>{b.text}</h2>;
          case "h3":
            return <h3 key={i}>{b.text}</h3>;
          case "ul":
            return (
              <ul key={i}>
                {b.items.map((t) => (
                  <li key={t}>{t}</li>
                ))}
              </ul>
            );
          case "ol":
            return (
              <ol key={i}>
                {b.items.map((t) => (
                  <li key={t}>{t}</li>
                ))}
              </ol>
            );
          case "pre":
            return (
              <pre key={i}>
                <code>{b.code}</code>
              </pre>
            );
          case "img":
            return (
              <figure key={i}>
                <img alt={b.alt} src={b.src} loading="lazy" referrerPolicy="no-referrer" />
                {b.caption && <figcaption>{b.caption}</figcaption>}
              </figure>
            );
          default:
            return <p key={i}>{b.text}</p>;
        }
      })}
    </div>
  );
}
