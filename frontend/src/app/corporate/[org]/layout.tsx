import CorporateNav from "@/components/corporate/CorporateNav";
import { api, requireUser } from "@/lib/auth";
import { type CorporateOrg } from "@/lib/corporate";
import { getSummary } from "@/lib/corporate-server";

export const instant = false;

/** /corporate/[org]/* — workspace satu organisasi. API memverifikasi keanggotaan aktif; organisasi lain → 404. */
export default async function OrgLayout({ children, params }: LayoutProps<"/corporate/[org]">) {
  const { org } = await params;
  const { token } = await requireUser(`/corporate/${org}`);
  const [s, orgs] = await Promise.all([getSummary(org), api<{ data: CorporateOrg[] }>("/corporate/organizations", { token }).then((r) => r.data)]);
  return (
    <div className="wrap pgrid">
      <CorporateNav org={org} name={s.organization.name} role={s.me.roleLabel} unit={s.me.unit} capabilities={s.me.capabilities} multiOrg={orgs.length > 1} />
      <section className="pmain">{children}</section>
    </div>
  );
}
