import Link from "next/link";
import { notFound } from "next/navigation";
import { BackLink, PortalCard } from "@/components/portal/ui";
import { api, ApiRequestError } from "@/lib/auth";
import { type ClassOverview, ENROLLMENT_STATUS, pct } from "@/lib/monitoring";
import { requireTrainer } from "@/lib/trainer";

export const instant = false;

/** /trainer/progres/[code] — tabel progress overview seluruh peserta: kehadiran, materi, kuis, tugas, praktik, kompetensi, catatan. */
export default async function TrainerClassProgress({ params }: PageProps<"/trainer/progres/[code]">) {
  const { code } = await params;
  const { token } = await requireTrainer(`/trainer/progres/${code}`);
  let d: ClassOverview;
  try {
    d = (await api<{ data: ClassOverview }>(`/trainer/classes/${encodeURIComponent(code)}/progress`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    throw e;
  }
  const n = d.participants.length;
  const avg = (vals: (number | null)[]) => {
    const v = vals.filter((x): x is number => x !== null);
    return v.length ? Math.round(v.reduce((a, b) => a + b, 0) / v.length) : null;
  };

  return (
    <>
      <BackLink href="/trainer/progres">Kembali ke Progres peserta</BackLink>
      <div className="kic pstats">
        <div className="kic1">
          <small>Peserta</small>
          <b>{n}</b>
        </div>
        <div className="kic1">
          <small>Rata-rata kehadiran</small>
          <b>{pct(avg(d.participants.map((p) => p.attendance_percent)))}</b>
        </div>
        <div className="kic1">
          <small>Rata-rata materi</small>
          <b>{pct(avg(d.participants.map((p) => p.percent)))}</b>
        </div>
        <div className="kic1">
          <small>Kompeten (tervalidasi)</small>
          <b>
            {d.participants.filter((p) => p.competency_status === "validated" && p.is_competent).length}/{n}
          </b>
        </div>
      </div>
      <PortalCard
        title={`Progres — ${d.class.title}`}
        action={
          <span style={{ display: "flex", gap: 8 }}>
            <Link className="jb" href={`/trainer/logbook/${code}`}>
              Logbook
            </Link>
            <Link className="jb" href={`/trainer/penilaian/${code}`}>
              Penilaian
            </Link>
          </span>
        }
      >
        {n === 0 ? (
          <p className="fh">Belum ada peserta.</p>
        ) : (
          <div style={{ overflowX: "auto" }}>
            <table className="att lqm" style={{ marginTop: 12 }}>
              <thead>
                <tr>
                  <th>Peserta</th>
                  <th>Kehadiran</th>
                  <th>Materi</th>
                  <th>Kuis</th>
                  <th>Tugas</th>
                  <th>Praktik</th>
                  <th>Kompetensi</th>
                  <th>Catatan</th>
                </tr>
              </thead>
              <tbody>
                {d.participants.map((p) => (
                  <tr key={p.enrollment_id}>
                    <td>
                      <b>
                        <Link href={`/trainer/progres/${code}/${p.enrollment_id}`}>{p.name}</Link>
                      </b>
                      <small>{ENROLLMENT_STATUS[p.status] ?? p.status}</small>
                    </td>
                    <td>{pct(p.attendance_percent)}</td>
                    <td>
                      <b>{p.percent}%</b>
                      <small>
                        {p.lessons_completed}/{p.lessons_total} lesson
                      </small>
                    </td>
                    <td>
                      <b>{pct(p.quiz_avg)}</b>
                      <small>
                        {p.quiz_done}/{p.quiz_total}
                      </small>
                    </td>
                    <td>
                      <b>
                        {p.assignments_graded}/{p.assignments_total}
                      </b>
                      <small>{p.assignments_submitted} dikumpulkan</small>
                    </td>
                    <td>
                      <b>{p.practice_completed} selesai</b>
                      <small>
                        {p.practice_partial} sebagian, {p.practice_not_done} belum
                      </small>
                    </td>
                    <td>
                      {p.competency_status && (p.competency_percent !== null || p.competency_status === "validated") ? (
                        <>
                          <span className={"jst " + (p.competency_status === "validated" ? (p.is_competent ? "ok" : "no") : "wn")}>{pct(p.competency_percent)}</span>
                          <small>{p.competency_status === "validated" ? (p.is_competent ? "kompeten" : "belum kompeten") : "draf"}</small>
                        </>
                      ) : (
                        <span className="fh">belum dinilai</span>
                      )}
                    </td>
                    <td>{p.notes_count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </PortalCard>
    </>
  );
}
