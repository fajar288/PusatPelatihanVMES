import Link from "next/link";
import { EmptyState, MetaList, PortalCard } from "@/components/portal/ui";
import { can, type CorporateRequest, orgPath, REQUEST_PILL } from "@/lib/corporate";
import { corporateApi, getSummary } from "@/lib/corporate-server";

export const instant = false;

/** /corporate/[org]/permintaan — daftar Corporate Training Request (dalam batas unit pengguna). */
export default async function RequestsPage({ params }: PageProps<"/corporate/[org]/permintaan">) {
  const { org } = await params;
  const [s, rows] = await Promise.all([getSummary(org), corporateApi<{ data: CorporateRequest[] }>(orgPath(org, "/requests"), `/corporate/${org}/permintaan`).then((r) => r.data)]);

  return (
    <PortalCard
      title="Permintaan Training"
      action={
        can(s, "manage_requests") ? (
          <Link className="lk" href={`/corporate/${org}/permintaan/baru`}>
            + Permintaan baru
          </Link>
        ) : undefined
      }
    >
      {rows.length === 0 ? (
        <EmptyState
          text="Belum ada permintaan training. Ajukan berdasarkan kebutuhan dan jumlah kursi — daftar peserta bisa diisi kemudian."
          href={can(s, "manage_requests") ? `/corporate/${org}/permintaan/baru` : undefined}
          cta="Buat permintaan"
        />
      ) : (
        <ul className="sp">
          {rows.map((r) => (
            <li key={r.code}>
              <span className="spl">
                <b>
                  <Link href={`/corporate/${org}/permintaan/${r.code}`}>{r.program ?? r.code}</Link>
                </b>
                <MetaList
                  compact
                  items={[
                    { icon: "code", value: r.code },
                    { icon: "users", label: "Kursi", value: `${r.filled}/${r.seats} terisi${r.invited ? `, ${r.invited} diundang` : ""}` },
                    { icon: "cal", value: r.schedule ? `${r.schedule.code} · ${r.schedule.dates}` : r.targetLabel ? `Target ${r.targetLabel}` : null },
                    { icon: "pin", value: r.deliveryLabel },
                    { value: r.unit },
                  ]}
                />
              </span>
              <b className="spv">
                <span className={`jst ${REQUEST_PILL[r.status]}`}>{r.statusLabel}</span>
              </b>
            </li>
          ))}
        </ul>
      )}
    </PortalCard>
  );
}
