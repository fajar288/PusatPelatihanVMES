import Link from "next/link";
import { notFound } from "next/navigation";
import { BackLink, PortalCard } from "@/components/portal/ui";
import LogbookCard from "@/components/trainer/LogbookCard";
import LogbookForm from "@/components/trainer/LogbookForm";
import { api, ApiRequestError } from "@/lib/auth";
import type { LogbooksData } from "@/lib/monitoring";
import { requireTrainer } from "@/lib/trainer";

export const instant = false;

/** /trainer/logbook/[code] — daftar logbook kelas + form pertemuan baru. */
export default async function TrainerClassLogbook({ params }: PageProps<"/trainer/logbook/[code]">) {
  const { code } = await params;
  const { token } = await requireTrainer(`/trainer/logbook/${code}`);
  let d: LogbooksData;
  try {
    d = (await api<{ data: LogbooksData }>(`/trainer/classes/${encodeURIComponent(code)}/logbooks`, { token })).data;
  } catch (e) {
    if (e instanceof ApiRequestError && (e.error.status === 404 || e.error.status === 403)) notFound();
    throw e;
  }
  const validated = d.logbooks.filter((l) => l.status === "validated").length;
  const latest = [...d.logbooks].reverse();

  return (
    <>
      <BackLink href="/trainer/logbook">Kembali ke Logbook</BackLink>
      <PortalCard
        title={`Logbook — ${d.class.title}`}
        action={
          <span style={{ display: "flex", gap: 8 }}>
            <Link className="jb" href={`/trainer/progres/${code}`}>
              Progres peserta
            </Link>
            <Link className="jb" href={`/trainer/penilaian/${code}`}>
              Penilaian
            </Link>
          </span>
        }
      >
        <ul className="sp">
          <li>
            <span>Kelas</span>
            <b className="spv">
              {d.class.code}
              <small>
                {d.class.dateLabel} – {d.class.endLabel}
              </small>
            </b>
          </li>
          <li>
            <span>Sesi terjadwal</span>
            <b>
              {d.sessions.filter((s) => s.hasLogbook).length}/{d.sessions.length} sudah ada logbook
            </b>
          </li>
          <li>
            <span>Logbook</span>
            <b className="spv">
              {d.logbooks.length} pertemuan
              <small>{validated} tervalidasi</small>
            </b>
          </li>
        </ul>
      </PortalCard>

      <PortalCard title="Catat pertemuan baru">
        <LogbookForm d={d} />
      </PortalCard>

      {latest.length > 0 && (
        <h2 style={{ fontSize: 20, marginTop: 28 }}>
          Riwayat pertemuan <small className="fh">({latest.length})</small>
        </h2>
      )}
      {latest.map((l) => (
        <LogbookCard key={`${l.id}-${l.updatedAt ?? ""}`} d={d} l={l} />
      ))}
    </>
  );
}
