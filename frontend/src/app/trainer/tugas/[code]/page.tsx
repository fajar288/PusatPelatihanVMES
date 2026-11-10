import Link from "next/link";
import { notFound } from "next/navigation";
import { PortalCard } from "@/components/portal/ui";
import GradeForm from "@/components/trainer/GradeForm";
import { api, ApiRequestError } from "@/lib/auth";
import type { TrainerSubmissions } from "@/lib/lms";
import { requireTrainer } from "@/lib/trainer";

export const instant = false;

/** /trainer/tugas/[code] — daftar pengumpulan tugas satu kelas + form penilaian. */
export default async function TrainerClassAssignments({ params }: PageProps<"/trainer/tugas/[code]">) {
  const { code } = await params;
  const { token } = await requireTrainer(`/trainer/tugas/${code}`);
  let d: TrainerSubmissions;
  try {
    d = (await api<{ data: TrainerSubmissions }>(`/trainer/classes/${encodeURIComponent(code)}/submissions`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    throw e;
  }
  const pending = d.submissions.filter((s) => s.status === "submitted");
  const others = d.submissions.filter((s) => s.status !== "submitted");

  return (
    <>
      <Link className="bk" href="/trainer/tugas">
        ← Penilaian tugas
      </Link>
      <PortalCard title={`Tugas — ${d.class.title}`} action={<span className={"jst " + (pending.length ? "wn" : "ok")}>{pending.length} menunggu penilaian</span>}>
        {d.assignments.length === 0 ? (
          <p className="fh">Belum ada lesson bertipe tugas pada course kelas ini.</p>
        ) : (
          <ul className="sp">
            {d.assignments.map((a) => (
              <li key={a.lessonId}>
                <span>
                  {a.title} <small className="fh">• {a.course}</small>
                </span>
                <b>
                  {d.submissions.filter((s) => s.lessonId === a.lessonId).length}/{d.participantsCount} dikumpulkan • maks. {a.maxScore}
                </b>
              </li>
            ))}
          </ul>
        )}
      </PortalCard>
      {pending.map((s) => (
        <GradeForm key={s.id} s={s} code={code} />
      ))}
      {others.length > 0 && (
        <>
          <h2 style={{ fontSize: 20, marginTop: 28 }}>Sudah dinilai / dikembalikan</h2>
          {others.map((s) => (
            <GradeForm key={s.id} s={s} code={code} />
          ))}
        </>
      )}
    </>
  );
}
