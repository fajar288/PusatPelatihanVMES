import Link from "next/link";
import { notFound } from "next/navigation";
import { PortalCard } from "@/components/portal/ui";
import AttendanceForm from "@/components/trainer/AttendanceForm";
import { api, ApiRequestError } from "@/lib/auth";
import { CLASS_STATUS, requireTrainer, ROLE_LABEL, type TrainerClass } from "@/lib/trainer";

export const instant = false;

/** /trainer/kelas/[code] — detail kelas: info, sesi, peserta, pencatatan kehadiran. */
export default async function TrainerClassDetail({ params }: PageProps<"/trainer/kelas/[code]">) {
  const { code } = await params;
  const { token } = await requireTrainer(`/trainer/kelas/${code}`);
  let cls: TrainerClass;
  try {
    cls = (await api<{ data: TrainerClass }>(`/trainer/classes/${encodeURIComponent(code)}`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    throw e;
  }
  const participants = cls.participants ?? [];

  return (
    <>
      <Link className="bk" href="/trainer/kelas">
        ← Kelas saya
      </Link>
      <PortalCard title={cls.title} action={<span className={"jst " + (cls.status === "cancelled" ? "no" : "ok")}>{CLASS_STATUS[cls.status] ?? cls.status}</span>}>
        <ul className="sp">
          <li>
            <span>Kode kelas</span>
            <b>{cls.code}</b>
          </li>
          <li>
            <span>Jadwal</span>
            <b>
              {cls.dateLabel} – {cls.endLabel}
              {cls.time ? ` • ${cls.time}` : ""}
            </b>
          </li>
          <li>
            <span>Lokasi</span>
            <b>
              {cls.location || cls.city} • {cls.mode}
            </b>
          </li>
          <li>
            <span>Tim pengajar</span>
            <b>{cls.trainers.map((t) => `${t.name} (${ROLE_LABEL[t.role] ?? t.role})`).join(", ")}</b>
          </li>
          <li>
            <span>Peserta</span>
            <b>
              {participants.length}
              {cls.quota ? ` dari kuota ${cls.quota}` : ""}
            </b>
          </li>
        </ul>
      </PortalCard>

      <PortalCard title="Sesi">
        {cls.sessions.length ? (
          <ul className="sp">
            {cls.sessions.map((s) => (
              <li key={s.no}>
                <span>
                  Sesi {s.no} • {s.dateLabel}
                  {s.time ? ` • ${s.time}` : ""}
                  {s.topic ? ` • ${s.topic}` : ""}
                </span>
                <b>
                  {s.marked ?? 0}/{participants.length} dicatat
                </b>
              </li>
            ))}
          </ul>
        ) : (
          <p className="fh">Belum ada sesi.</p>
        )}
      </PortalCard>

      <PortalCard title="Kehadiran">
        <AttendanceForm cls={cls} />
      </PortalCard>
    </>
  );
}
