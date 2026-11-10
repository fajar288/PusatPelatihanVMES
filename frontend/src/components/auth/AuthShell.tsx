import type { ReactNode } from "react";

/** Kerangka halaman auth: judul `.ph0` + kartu `.kbx` sempit di tengah (gaya tema). */
export default function AuthShell({ title, subtitle, children, footer }: { title: string; subtitle?: ReactNode; children: ReactNode; footer?: ReactNode }) {
  return (
    <>
      <section className="ph0">
        <div className="wrap">
          <h1>{title}</h1>
          {subtitle && <p>{subtitle}</p>}
        </div>
      </section>
      <div className="single">
        <div className="wrap">
          <div className="in" style={{ maxWidth: 520 }}>
            <div className="kbx" style={{ marginTop: 0 }}>
              {children}
            </div>
            {footer && (
              <p className="fh" style={{ marginTop: 18, textAlign: "center", color: "var(--mut)", fontWeight: 600, fontSize: 14 }}>
                {footer}
              </p>
            )}
            <div style={{ height: 40 }} />
          </div>
        </div>
      </div>
    </>
  );
}

/** Pesan error/sukses form (gaya .fok tema + varian error). */
export function FormMessage({ ok, message }: { ok?: boolean; message?: string }) {
  if (!message) return null;
  return (
    <div className="fok" style={ok ? undefined : { background: "#fff3e8", borderColor: "#ffd2ab", color: "#b34200" }} role={ok ? "status" : "alert"}>
      {message}
    </div>
  );
}

export function FieldError({ errors, name }: { errors?: Record<string, string[]>; name: string }) {
  const msg = errors?.[name]?.[0];
  return msg ? (
    <p className="fh" style={{ color: "#b34200", marginTop: 6 }}>
      {msg}
    </p>
  ) : null;
}
