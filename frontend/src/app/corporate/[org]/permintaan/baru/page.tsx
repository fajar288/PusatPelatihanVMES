import { notFound } from "next/navigation";
import NewRequestForm from "@/components/corporate/NewRequestForm";
import { BackLink, PortalCard } from "@/components/portal/ui";
import { getPackages } from "@/lib/cms";
import { can, orgPath, type Unit } from "@/lib/corporate";
import { corporateApi, getSummary } from "@/lib/corporate-server";

export const instant = false;

/** /corporate/[org]/permintaan/baru — ajukan kebutuhan training (jumlah kursi; nama peserta menyusul). */
export default async function NewRequestPage({ params }: PageProps<"/corporate/[org]/permintaan/baru">) {
  const { org } = await params;
  const s = await getSummary(org);
  if (!can(s, "manage_requests")) notFound();
  const [catalog, units] = await Promise.all([getPackages(), corporateApi<{ data: Unit[] }>(orgPath(org, "/units"), `/corporate/${org}/permintaan/baru`).then((r) => r.data)]);
  const packages = catalog.items.map((p) => ({ slug: p.slug, title: p.title, category: p.category ?? null }));

  return (
    <>
      <BackLink href={`/corporate/${org}/permintaan`}>Kembali ke Permintaan Training</BackLink>
      <PortalCard title="Permintaan training baru">
        <p className="fh">
          Tentukan program dan jumlah kursi. Sistem membuat kursi 01–N tanpa membuat akun peserta; nama peserta diisi kemudian lewat input manual, unggah Excel/CSV, atau undangan email.
        </p>
        <NewRequestForm org={org} packages={packages} units={units} />
      </PortalCard>
    </>
  );
}
