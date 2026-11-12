import { notFound } from "next/navigation";
import CorporateUsersManager from "@/components/corporate/CorporateUsersManager";
import { PortalCard } from "@/components/portal/ui";
import { can, type CorporateUserRow, orgPath, type Unit } from "@/lib/corporate";
import { corporateApi, getSummary } from "@/lib/corporate-server";

export const instant = false;

/** /corporate/[org]/pengguna — Corporate Owner mengelola pengguna perusahaan & perannya (§20.12). Semua perubahan diaudit. */
export default async function CorporateUsersPage({ params }: PageProps<"/corporate/[org]/pengguna">) {
  const { org } = await params;
  const s = await getSummary(org);
  if (!can(s, "manage_users")) notFound();
  const next = `/corporate/${org}/pengguna`;
  const [users, units] = await Promise.all([
    corporateApi<{ data: CorporateUserRow[] }>(orgPath(org, "/users"), next).then((r) => r.data),
    corporateApi<{ data: Unit[] }>(orgPath(org, "/units"), next).then((r) => r.data),
  ]);
  return (
    <PortalCard title="Pengguna Perusahaan">
      <p className="fh">
        Pengguna masuk dengan akun VMES miliknya sendiri (email yang sama dengan Portal Member). Peran menentukan menu dan data yang terlihat; batas unit membatasi supervisor ke departemennya. Mencabut akses berlaku seketika dan tidak menghapus data peserta.
      </p>
      <CorporateUsersManager org={org} users={users} units={units} />
    </PortalCard>
  );
}
