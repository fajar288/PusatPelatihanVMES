import BulkImportForm from "@/components/corporate/BulkImportForm";
import RequestActions from "@/components/corporate/RequestActions";
import SeatAssignPanel from "@/components/corporate/SeatAssignPanel";
import SeatReleaseButton from "@/components/corporate/SeatReleaseButton";
import { BackLink, MetaList, PortalCard } from "@/components/portal/ui";
import { can, type CorporateRequest, type Employee, orgPath, REQUEST_PILL, rupiah, type Unit } from "@/lib/corporate";
import { corporateApi, getSummary } from "@/lib/corporate-server";

export const instant = false;

const SEAT_PILL: Record<string, "ok" | "wn" | "no"> = { enrolled: "ok", assigned: "ok", invited: "wn", reserved: "no" };

/** /corporate/[org]/permintaan/[code] — detail permintaan, penawaran, persetujuan, kursi & penugasan peserta. */
export default async function RequestDetailPage({ params }: PageProps<"/corporate/[org]/permintaan/[code]">) {
  const { org, code } = await params;
  const next = `/corporate/${org}/permintaan/${code}`;
  const s = await getSummary(org);
  const r = (await corporateApi<{ data: CorporateRequest }>(orgPath(org, `/requests/${encodeURIComponent(code)}`), next)).data;
  const assign = r.can.assign;
  const [employees, units] = assign
    ? await Promise.all([
        corporateApi<{ data: Employee[] }>(orgPath(org, "/employees"), next).then((x) => x.data),
        corporateApi<{ data: Unit[] }>(orgPath(org, "/units"), next).then((x) => x.data),
      ])
    : [[], []];
  const seats = r.seatList ?? [];
  const free = seats.filter((x) => x.status === "reserved");

  return (
    <>
      <BackLink href={`/corporate/${org}/permintaan`}>Kembali ke Permintaan Training</BackLink>
      <PortalCard title={r.program ?? r.code} action={<span className={`jst ${REQUEST_PILL[r.status]}`}>{r.statusLabel}</span>}>
        <MetaList
          items={[
            { icon: "code", label: "Kode", value: r.code },
            { icon: "users", label: "Kursi", value: `${r.filled}/${r.seats} terisi${r.invited ? `, ${r.invited} diundang` : ""}` },
            { icon: "pin", label: "Delivery", value: `${r.deliveryLabel}${r.location ? ` · ${r.location}` : ""}` },
            { icon: "cal", label: r.schedule ? "Batch" : "Target", value: r.schedule ? `${r.schedule.code} · ${r.schedule.dates}` : r.targetLabel },
            { icon: "brief", label: "Unit", value: r.unit },
          ]}
        />
        {(r.trainerRequirements || r.notes) && (
          <div className="fh" style={{ marginTop: 12 }}>
            {r.trainerRequirements && (
              <p>
                <b>Kebutuhan trainer:</b> {r.trainerRequirements}
              </p>
            )}
            {r.notes && (
              <p>
                <b>Catatan:</b> {r.notes}
              </p>
            )}
          </div>
        )}
        {r.cancelReason && (
          <p className="fh" style={{ marginTop: 12 }}>
            <b>Alasan pembatalan:</b> {r.cancelReason}
          </p>
        )}
        {r.quote && (
          <div className="pcert" style={{ alignItems: "flex-start" }}>
            <div>
              <b style={{ color: "var(--ink)" }}>Penawaran VMES</b>
              <MetaList
                compact
                style={{ marginTop: 4 }}
                items={[
                  { label: "Per kursi", value: rupiah(r.quote.pricePerSeat) },
                  r.quote.discount > 0 && { label: "Diskon", value: rupiah(r.quote.discount) },
                  { label: "Total", value: <b>{rupiah(r.quote.total)}</b> },
                  { label: "Berlaku sampai", value: `${r.quote.validUntil ?? "-"}${r.quote.expired ? " (kedaluwarsa)" : ""}` },
                ]}
              />
              {r.quote.notes && <p className="fh" style={{ marginTop: 6, fontSize: 13 }}>{r.quote.notes}</p>}
            </div>
          </div>
        )}
        <RequestActions org={org} code={r.code} can={r.can} status={r.status} requiresApproval={s.organization.requiresApproval} />
        {r.timeline && Object.keys(r.timeline).length > 0 && (
          <MetaList compact style={{ marginTop: 14 }} items={Object.entries(r.timeline).map(([k, v]) => ({ icon: "clock" as const, label: k, value: v }))} />
        )}
      </PortalCard>

      <PortalCard title={`Kursi & peserta (${r.filled}/${r.seats})`}>
        <p className="fh">
          Kursi dipesan tanpa membuat akun peserta. Peserta yang sudah punya akun terverifikasi langsung ditetapkan; selain itu sistem mengirim <b>undangan ke email peserta</b> agar mereka masuk dengan akunnya sendiri dan memberi persetujuan. Setelah VMES menetapkan batch, peserta otomatis terdaftar di kelas.
        </p>
        <ul className="sp" style={{ marginTop: 12 }}>
          {seats.map((x) => (
            <li key={x.id}>
              <span className="spl">
                <b>
                  Kursi {String(x.no).padStart(2, "0")}
                  {x.name ? ` · ${x.name}` : ""}
                </b>
                <small>
                  {x.email ?? "Belum diisi"}
                  {x.unit ? ` · ${x.unit}` : ""}
                  {x.inviteExpires ? ` · undangan berlaku s.d. ${x.inviteExpires}` : ""}
                </small>
              </span>
              <b className="spv">
                <span className={`jst ${SEAT_PILL[x.status] ?? "no"}`}>{x.statusLabel}</span>
                {assign && x.status !== "reserved" && <SeatReleaseButton org={org} code={r.code} seat={x.id} label={`kursi ${x.no}`} />}
              </b>
            </li>
          ))}
        </ul>
      </PortalCard>

      {assign && free.length > 0 && (
        <PortalCard title="Isi peserta">
          <SeatAssignPanel org={org} code={r.code} freeSeats={free.map((x) => ({ id: x.id, no: x.no }))} employees={employees} units={units} />
        </PortalCard>
      )}

      {assign && free.length > 0 && can(s, "manage_participants") && (
        <PortalCard title="Unggah banyak peserta (Excel / CSV)">
          <BulkImportForm org={org} code={r.code} templateHref={`/corporate/${org}/permintaan/${r.code}/template`} />
        </PortalCard>
      )}
    </>
  );
}
