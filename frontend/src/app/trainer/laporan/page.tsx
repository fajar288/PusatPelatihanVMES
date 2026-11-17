import Link from "next/link";
import { EmptyState, PortalCard } from "@/components/portal/ui";
import { ScrollX } from "@/components/ui/ScrollX";
import { api } from "@/lib/auth";
import { pct } from "@/lib/monitoring";
import { fmtScore } from "@/lib/surveys";
import { CLASS_STATUS, requireTrainer, type TrainerReportClass } from "@/lib/trainer";

export const instant = false;

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";
const isDate = (v: string) => /^\d{4}-\d{2}-\d{2}$/.test(v);
const num = (v: number | null) => (v === null ? "-" : String(v).replace(".", ","));

/**
 * /trainer/laporan - ringkasan kelas yang ditugaskan: peserta, kehadiran, nilai akhir, kelulusan, progres LMS,
 * dan skor survei (tampil bila pengisi sudah cukup). Rincian per peserta di halaman Progres, ekspor CSV/XLSX per kelas.
 */
export default async function TrainerReportPage({ searchParams }: PageProps<"/trainer/laporan">) {
  const sp = await searchParams;
  const from = first(sp.from);
  const to = first(sp.to);
  const status = first(sp.status);
  const qs = new URLSearchParams({
    ...(isDate(from) ? { from } : {}),
    ...(isDate(to) ? { to } : {}),
    ...(status in CLASS_STATUS ? { status } : {}),
  });
  const { token } = await requireTrainer(`/trainer/laporan${qs.size ? `?${qs}` : ""}`);
  const classes = (await api<{ data: TrainerReportClass[] }>(`/trainer/reports${qs.size ? `?${qs}` : ""}`, { token })).data;
  const total = classes.reduce((n, c) => n + c.participants, 0);

  return (
    <>
      <PortalCard title="Laporan Kelas">
        <form className="ff rpt-f" style={{ marginBottom: 6 }}>
          <div>
            <label htmlFor="lf">Dari</label>
            <input id="lf" name="from" type="date" defaultValue={isDate(from) ? from : ""} />
          </div>
          <div>
            <label htmlFor="lt">Sampai</label>
            <input id="lt" name="to" type="date" defaultValue={isDate(to) ? to : ""} />
          </div>
          <div>
            <label htmlFor="ls">Status kelas</label>
            <select id="ls" name="status" defaultValue={status in CLASS_STATUS ? status : ""}>
              <option value="">Semua status</option>
              {Object.entries(CLASS_STATUS).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </div>
          <button className="kb2 px-6! py-3!" type="submit">
            Terapkan
          </button>
          {qs.size > 0 && (
            <Link className="jb" href="/trainer/laporan">
              Reset
            </Link>
          )}
        </form>
        <p className="fh" style={{ fontSize: 13 }}>
          Hanya kelas yang ditugaskan kepada Anda. Periode memilih kelas yang berlangsung di antara kedua tanggal. Kehadiran dihitung dari sesi yang sudah berlangsung; skor survei tampil bila sudah ada minimal 3 pengisi agar jawaban tidak bisa ditebak.
        </p>
        <div className="kic pstats" style={{ marginTop: 14 }}>
          <div className="kic1">
            <small>Kelas</small>
            <b>{classes.length}</b>
          </div>
          <div className="kic1">
            <small>Peserta</small>
            <b>{total}</b>
          </div>
          <div className="kic1">
            <small>Lulus</small>
            <b>{classes.reduce((n, c) => n + c.passed, 0)}</b>
          </div>
          <div className="kic1">
            <small>Belum dievaluasi</small>
            <b>{classes.reduce((n, c) => n + c.notEvaluated, 0)}</b>
          </div>
        </div>
      </PortalCard>

      <PortalCard title="Ringkasan per kelas">
        {classes.length === 0 ? (
          <EmptyState text={qs.size ? "Tidak ada kelas pada filter ini." : "Belum ada kelas yang ditugaskan."} />
        ) : (
          <ScrollX label="Tabel laporan kelas">
            <table className="att lqm rpt" style={{ marginTop: 12 }}>
              <thead>
                <tr>
                  <th className="rpt-kls">Kelas</th>
                  <th>Peserta</th>
                  <th>Kehadiran</th>
                  <th>Nilai akhir</th>
                  <th>Lulus / tidak / belum</th>
                  <th>Progres LMS</th>
                  <th>Survei</th>
                  <th>Ekspor</th>
                </tr>
              </thead>
              <tbody>
                {classes.map((c) => (
                  <tr key={c.code}>
                    <td className="rpt-kls">
                      <b>
                        <Link href={`/trainer/progres/${encodeURIComponent(c.code)}`}>{c.title}</Link>
                      </b>
                      <small>{c.code}</small>
                      <small>
                        {c.dateLabel} s.d. {c.endLabel}
                      </small>
                      <small>{CLASS_STATUS[c.status] ?? c.status}</small>
                    </td>
                    <td data-l="Peserta">
                      <b>{c.participants}</b>
                      {c.dropped > 0 && <small>{c.dropped} mundur</small>}
                    </td>
                    <td data-l="Kehadiran">{pct(c.attendanceRate)}</td>
                    <td data-l="Nilai akhir">{num(c.averageFinalScore)}</td>
                    <td data-l="Lulus / tidak / belum">
                      <b>
                        {c.passed} / {c.failed} / {c.notEvaluated}
                      </b>
                      {c.completionRate !== null && <small>Kelulusan {pct(c.completionRate)}</small>}
                    </td>
                    <td data-l="Progres LMS">{pct(c.lmsAverage)}</td>
                    <td data-l="Survei">
                      <b>
                        <Link href={`/trainer/survei/${encodeURIComponent(c.code)}`}>{c.survey.hidden ? "-" : fmtScore(c.survey.average)}</Link>
                      </b>
                      <small>
                        {c.survey.submitted}/{c.survey.invited} pengisi
                        {c.survey.hidden && c.survey.invited > 0 ? `, tampil setelah ${c.survey.minResponses}` : ""}
                      </small>
                    </td>
                    <td data-l="Ekspor">
                      <span style={{ display: "flex", gap: 8, whiteSpace: "nowrap" }}>
                        <a className="jb" href={`/trainer/laporan/ekspor/${encodeURIComponent(c.code)}/csv`} download>
                          CSV
                        </a>
                        <a className="jb" href={`/trainer/laporan/ekspor/${encodeURIComponent(c.code)}/xlsx`} download>
                          XLSX
                        </a>
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </ScrollX>
        )}
        <p className="fh" style={{ marginTop: 12, fontSize: 13 }}>
          Klik nama kelas untuk rincian per peserta (kehadiran, materi, kuis, tugas, praktik, kompetensi), atau skor survei untuk laporan survei kelas.
        </p>
      </PortalCard>
    </>
  );
}
