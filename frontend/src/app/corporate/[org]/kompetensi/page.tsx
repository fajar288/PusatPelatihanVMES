import Link from "next/link";
import CompetencyTargetsManager from "@/components/corporate/CompetencyTargetsManager";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { type CorporateCompetencies, orgPath, pct } from "@/lib/corporate";
import { corporateApi } from "@/lib/corporate-server";

export const instant = false;

const SOURCE: Record<string, string> = {
  assessment: "penilaian",
  training: "program lulus",
};

/**
 * /corporate/[org]/kompetensi — Competency Matrix (PRD Roadmap 5 §4.6, §20.16): target per posisi, lalu per karyawan
 * Required / Actual / Gap / Rekomendasi. Bukti hanya dari training yang disponsori perusahaan; nama karyawan hanya untuk
 * peran yang boleh melihat progres.
 */
export default async function CompetencyPage({ params }: PageProps<"/corporate/[org]/kompetensi">) {
  const { org } = await params;
  const d = (await corporateApi<{ data: CorporateCompetencies }>(orgPath(org, "/competencies"), `/corporate/${org}/kompetensi`)).data;

  return (
    <>
      <PortalCard title="Matriks kompetensi">
        <p className="fh">
          Level aktual diambil dari skor penilaian kompetensi tervalidasi pada training yang disponsori perusahaan, atau dari program yang sudah diluluskan bila belum ada
          penilaian. Training mandiri karyawan tidak dihitung.
        </p>
        <div className="kic pstats" style={{ marginTop: 14 }}>
          <div className="kic1">
            <small>Karyawan aktif</small>
            <b>{d.employees}</b>
          </div>
          <div className="kic1">
            <small>Punya target</small>
            <b>{d.employeesWithTargets}</b>
            <small style={{ textTransform: "none", letterSpacing: 0 }}>posisinya sudah diberi target</small>
          </div>
          {d.summary.slice(0, 3).map((c) => (
            <div className="kic1" key={c.code}>
              <small>{c.competency}</small>
              <b>{pct(c.coverage)}</b>
              <small style={{ textTransform: "none", letterSpacing: 0 }}>
                {c.met}/{c.employees} memenuhi target
              </small>
            </div>
          ))}
        </div>

        {d.rows === null ? (
          <p className="fh" style={{ marginTop: 14 }}>
            Peran Anda hanya melihat ringkasan cakupan kompetensi.
          </p>
        ) : d.rows.length === 0 ? (
          <div style={{ marginTop: 14 }}>
            <EmptyState text="Belum ada karyawan dengan posisi yang punya target kompetensi." />
          </div>
        ) : (
          <div style={{ overflowX: "auto", marginTop: 14 }}>
            <table className="att">
              <thead>
                <tr>
                  <th>Karyawan</th>
                  <th>Kompetensi</th>
                  <th>Target</th>
                  <th>Aktual</th>
                  <th>Gap</th>
                  <th>Rekomendasi</th>
                </tr>
              </thead>
              <tbody>
                {d.rows.map((r) => (
                  <tr key={`${r.employmentId}-${r.competency}`}>
                    <td>
                      <b>{r.name}</b>
                      <small>{[r.position, r.unit, r.employeeId].filter(Boolean).join(" · ")}</small>
                    </td>
                    <td>
                      <b>{r.competencyName}</b>
                      <small>{r.competency}</small>
                    </td>
                    <td>
                      <span className="sm:hidden">Target </span>
                      {r.required}
                    </td>
                    <td>
                      <b>
                        <span className="sm:hidden">Aktual </span>
                        {r.actual ?? "-"}
                      </b>
                      <small>{r.source ? SOURCE[r.source] : "belum ada bukti"}</small>
                    </td>
                    <td>
                      <span className="sm:hidden">Gap </span>
                      <span className={`jst ${r.met ? "ok" : r.gap >= 15 ? "no" : "wn"}`}>{r.met ? "Terpenuhi" : `-${r.gap}`}</span>
                    </td>
                    <td>
                      {!r.met && <span className="sm:hidden">Rekomendasi: </span>}
                      {r.recommendation ? <Link href={`/kursus/${r.recommendation.slug}`}>{r.recommendation.title}</Link> : r.met ? "-" : <small>Hubungi VMES</small>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </PortalCard>

      <PortalCard title="Target kompetensi per posisi">
        <CompetencyTargetsManager org={org} data={d} />
        {!d.canManage && (
          <p className="fh" style={{ marginTop: 12, fontSize: 13 }}>
            Target diatur oleh Corporate Owner atau HR/L&amp;D Admin tanpa batas unit.
          </p>
        )}
      </PortalCard>
    </>
  );
}
