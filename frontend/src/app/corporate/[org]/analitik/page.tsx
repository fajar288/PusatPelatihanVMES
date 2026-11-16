import Link from "next/link";
import { EmptyState, MetaList, PortalCard } from "@/components/portal/ui";
import { type CorporateAnalytics, type KpiKey, orgPath, pct, rupiah } from "@/lib/corporate";
import { corporateApi } from "@/lib/corporate-server";

export const instant = false;

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";
const isDate = (v: string) => /^\d{4}-\d{2}-\d{2}$/.test(v);
const num = (v: number | null | undefined, unit = "") => (v === null || v === undefined ? "-" : `${String(v).replace(".", ",")}${unit}`);
const tanggal = (iso: string | null) =>
  iso
    ? new Date(`${iso}T00:00:00`).toLocaleDateString("id-ID", {
        day: "numeric",
        month: "short",
        year: "numeric",
      })
    : "-";

const KPI_ORDER: KpiKey[] = ["completion_rate", "attendance_rate", "at_risk_rate", "training_conversion", "repeat_order_rate", "average_order_value"];

/**
 * /corporate/[org]/analitik — analitik perusahaan (PRD Roadmap 5 §8.2, §20.15): KPI §8.1 untuk training yang disponsori,
 * pemakaian kursi, kehadiran & jam per batch/departemen, efektivitas program, pembelian ulang, biaya (peran finance),
 * peserta berisiko (peran progres). Semua angka dibatasi server pada data sponsor organisasi & batas unit pengguna.
 */
export default async function AnalyticsPage({ params, searchParams }: PageProps<"/corporate/[org]/analitik">) {
  const { org } = await params;
  const sp = await searchParams;
  const from = first(sp.from);
  const to = first(sp.to);
  const qs = new URLSearchParams({
    ...(isDate(from) ? { from } : {}),
    ...(isDate(to) ? { to } : {}),
  });
  const d = (await corporateApi<{ data: CorporateAnalytics }>(orgPath(org, `/analytics?${qs}`), `/corporate/${org}/analitik`)).data;
  const u = d.utilization;

  return (
    <>
      <PortalCard title="Analitik training">
        <form className="ff flex flex-wrap items-end gap-3" style={{ marginBottom: 6 }}>
          <div className="min-w-[160px]">
            <label htmlFor="af">Dari</label>
            <input id="af" name="from" type="date" defaultValue={d.period.from} />
          </div>
          <div className="min-w-[160px]">
            <label htmlFor="at">Sampai</label>
            <input id="at" name="to" type="date" defaultValue={d.period.to} />
          </div>
          <button className="kb2 px-6! py-3!" type="submit">
            Terapkan
          </button>
        </form>
        <p className="fh" style={{ fontSize: 13 }}>
          Periode {tanggal(d.period.from)} s.d. {tanggal(d.period.to)}. Hanya training yang disponsori perusahaan; definisi setiap KPI tampil saat kursor diarahkan ke kartu.
        </p>
        <div className="kic pstats" style={{ marginTop: 14 }}>
          {KPI_ORDER.map((k) => {
            const v = d.kpi[k];
            return (
              <div className="kic1" key={k} title={v.definition}>
                <small>{v.label}</small>
                <b>{k === "average_order_value" ? (v.value === null ? "-" : rupiah(v.value)) : v.display}</b>
                <small style={{ textTransform: "none", letterSpacing: 0 }}>
                  {k === "average_order_value" ? `${v.denominator} order lunas` : `${v.numerator} dari ${v.denominator}`}
                  {k === "completion_rate" && v.pending_evaluation ? ` · ${v.pending_evaluation} belum dievaluasi` : ""}
                </small>
              </div>
            );
          })}
        </div>
      </PortalCard>

      <PortalCard title="Pemakaian kursi & jam training">
        <div className="kic pstats">
          <div className="kic1">
            <small>Kursi dibeli</small>
            <b>{u.bought}</b>
            <small style={{ textTransform: "none", letterSpacing: 0 }}>{u.requested} kursi diminta</small>
          </div>
          <div className="kic1">
            <small>Kursi terpakai</small>
            <b>{pct(u.rate)}</b>
            <small style={{ textTransform: "none", letterSpacing: 0 }}>
              {u.used} hadir/lulus dari {u.assigned} terisi
            </small>
          </div>
          <div className="kic1">
            <small>Jam training</small>
            <b>{num(d.trainingHours.total, " jam")}</b>
            <small style={{ textTransform: "none", letterSpacing: 0 }}>{d.participants} karyawan</small>
          </div>
          <div className="kic1">
            <small>Jam per karyawan</small>
            <b>{num(d.trainingHours.perEmployee, " jam")}</b>
            <small style={{ textTransform: "none", letterSpacing: 0 }}>dari sesi yang dihadiri</small>
          </div>
          <div className="kic1">
            <small>Pembelian ulang</small>
            <b>{d.repeat.isRepeat ? "Ya" : d.repeat.orders > 0 ? "Belum" : "-"}</b>
            <small style={{ textTransform: "none", letterSpacing: 0 }}>
              {d.repeat.orders} order lunas · {d.repeat.programs} program
            </small>
          </div>
        </div>
      </PortalCard>

      <PortalCard title="Per batch">
        {d.byBatch.length === 0 ? (
          <EmptyState text="Belum ada training bersponsor pada periode ini." />
        ) : (
          <div style={{ overflowX: "auto" }}>
            <table className="att" style={{ marginTop: 4 }}>
              <thead>
                <tr>
                  <th>Batch</th>
                  <th>Peserta</th>
                  <th>Kehadiran</th>
                  <th>Lulus</th>
                  <th>Jam</th>
                </tr>
              </thead>
              <tbody>
                {d.byBatch.map((b) => (
                  <tr key={b.code}>
                    <td>
                      <b>{b.program}</b>
                      <small>
                        {b.code} · {b.dates}
                      </small>
                    </td>
                    <td>{b.participants}</td>
                    <td>{pct(b.attendanceRate)}</td>
                    <td>
                      <b>{b.completed}</b>
                      <small>{b.completionRate === null ? "belum ada hasil" : `${pct(b.completionRate)} dari yang dinilai`}</small>
                    </td>
                    <td>{num(b.hours)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </PortalCard>

      <PortalCard title="Per departemen / unit">
        {d.byUnit.length === 0 ? (
          <EmptyState text="Belum ada data departemen." />
        ) : (
          <div style={{ overflowX: "auto" }}>
            <table className="att" style={{ marginTop: 4 }}>
              <thead>
                <tr>
                  <th>Unit</th>
                  <th>Karyawan</th>
                  <th>Kehadiran</th>
                  <th>Lulus</th>
                  <th>Jam / karyawan</th>
                </tr>
              </thead>
              <tbody>
                {d.byUnit.map((r) => (
                  <tr key={r.unit}>
                    <td>
                      <b>{r.unit}</b>
                      <small>{r.enrollments} enrollment</small>
                    </td>
                    <td>{r.participants}</td>
                    <td>{pct(r.attendanceRate)}</td>
                    <td>{r.completed}</td>
                    <td>
                      <b>{num(r.hoursPerEmployee)}</b>
                      <small>total {num(r.hours)} jam</small>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </PortalCard>

      <PortalCard title="Efektivitas program">
        {d.programs.length === 0 ? (
          <EmptyState text="Belum ada program pada periode ini." />
        ) : (
          <ul className="sp">
            {d.programs.map((p) => (
              <li key={p.slug ?? p.program}>
                <span className="spl">
                  <b>{p.program}</b>
                  <MetaList
                    compact
                    items={[
                      {
                        icon: "users",
                        label: "Peserta",
                        value: p.participants,
                      },
                      {
                        label: "Nilai akhir rata-rata",
                        value: p.avgScore === null ? null : num(p.avgScore),
                      },
                      {
                        label: "Kompeten",
                        value: p.competentRate === null ? null : pct(p.competentRate),
                      },
                      {
                        label: "Skor kompetensi",
                        value: p.avgCompetency === null ? null : pct(p.avgCompetency),
                      },
                    ]}
                  />
                </span>
                <b className="spv">
                  {pct(p.completionRate)}
                  <small>{p.completed} lulus</small>
                </b>
              </li>
            ))}
          </ul>
        )}
      </PortalCard>

      {d.cost && (
        <PortalCard title="Biaya training">
          <div className="kic pstats">
            <div className="kic1">
              <small>Total (order lunas)</small>
              <b>{rupiah(d.cost.total)}</b>
              <small style={{ textTransform: "none", letterSpacing: 0 }}>{d.cost.orders} order dalam periode</small>
            </div>
            <div className="kic1">
              <small>Biaya per karyawan</small>
              <b>{rupiah(d.cost.perEmployee)}</b>
              <small style={{ textTransform: "none", letterSpacing: 0 }}>{d.participants} karyawan ikut training</small>
            </div>
          </div>
          {d.cost.byUnit.length > 0 && (
            <ul className="sp" style={{ marginTop: 12 }}>
              {d.cost.byUnit.map((c) => (
                <li key={c.unit}>
                  <span className="spl">
                    <b>{c.unit}</b>
                    <small>{c.orders} order</small>
                  </span>
                  <b className="spv">{rupiah(c.amount)}</b>
                </li>
              ))}
            </ul>
          )}
        </PortalCard>
      )}

      <PortalCard title={`Peserta berisiko (${d.risk.count} dari ${d.risk.active} aktif)`}>
        {d.risk.rows === null ? (
          <p className="fh">Peran Anda hanya melihat jumlah peserta berisiko.</p>
        ) : d.risk.rows.length === 0 ? (
          <EmptyState
            text={d.risk.count > 0 ? "Peserta berisiko sudah tidak lagi bekerja di perusahaan, jadi detailnya tidak ditampilkan." : "Tidak ada peserta yang tertinggal."}
          />
        ) : (
          <ul className="sp">
            {d.risk.rows.map((r) => (
              <li key={r.enrollmentId}>
                <span className="spl">
                  <b>
                    <Link href={`/corporate/${org}/peserta/${r.enrollmentId}`}>{r.name}</Link>
                  </b>
                  <MetaList
                    compact
                    items={[
                      { icon: "cal", value: r.class },
                      { icon: "grad", value: r.program },
                    ]}
                  />
                  <small>{r.reasons.join("; ")}</small>
                </span>
                <b className="spv">
                  <span className="jst no">{pct(r.attendance)} hadir</span>
                </b>
              </li>
            ))}
          </ul>
        )}
      </PortalCard>

      {d.competency.length > 0 && (
        <PortalCard
          title="Cakupan kompetensi"
          action={
            <Link className="lk" href={`/corporate/${org}/kompetensi`}>
              Matriks kompetensi
            </Link>
          }
        >
          <ul className="sp">
            {d.competency.map((c) => (
              <li key={c.code}>
                <span className="spl">
                  <b>{c.competency}</b>
                  <small>
                    {c.met} dari {c.employees} karyawan memenuhi target · rata-rata gap {num(c.avg_gap)} poin
                    {c.no_evidence ? ` · ${c.no_evidence} belum ada bukti` : ""}
                  </small>
                </span>
                <b className="spv">{pct(c.coverage)}</b>
              </li>
            ))}
          </ul>
        </PortalCard>
      )}
    </>
  );
}
