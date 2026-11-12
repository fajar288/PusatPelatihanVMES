import Link from "next/link";
import { redirect } from "next/navigation";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { CorporateOrg } from "@/lib/corporate";

export const instant = false;

/** /corporate — pilih organisasi (langsung masuk bila hanya satu). */
export default async function CorporateIndex() {
  const { token } = await requireUser("/corporate");
  const orgs = (await api<{ data: CorporateOrg[] }>("/corporate/organizations", { token })).data;
  if (orgs.length === 1) redirect(`/corporate/${orgs[0].code}`);

  return (
    <div className="wrap" style={{ maxWidth: 880 }}>
      <PortalCard title="Pilih organisasi">
        {orgs.length === 0 ? (
          <EmptyState text="Akun Anda belum terdaftar sebagai pengguna perusahaan. Hubungi Corporate Owner perusahaan Anda atau tim VMES untuk mendapatkan akses." href="/portal" cta="Kembali ke Portal Member" />
        ) : (
          <ul className="sp">
            {orgs.map((o) => (
              <li key={o.code}>
                <span className="spl">
                  <b>
                    <Link href={`/corporate/${o.code}`}>{o.name}</Link>
                  </b>
                  <small>
                    {o.roleLabel}
                    {o.unit ? ` · ${o.unit}` : ""}
                  </small>
                </span>
                <Link className="lk" href={`/corporate/${o.code}`}>
                  Buka
                </Link>
              </li>
            ))}
          </ul>
        )}
      </PortalCard>
    </div>
  );
}
