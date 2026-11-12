import Link from "next/link";
import { EmptyState, MetaList, PortalCard } from "@/components/portal/ui";
import { can, CERT_STATUS, type CorporateEnrollment, orgPath } from "@/lib/corporate";
import { corporateApi, getSummary } from "@/lib/corporate-server";
import { ENROLLMENT_STATUS } from "@/lib/monitoring";

export const instant = false;

type Page = { data: CorporateEnrollment[]; meta: { currentPage: number; lastPage: number; total: number } };

const first = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

/** /corporate/[org]/peserta — enrollment yang disponsori perusahaan (R3) dalam batas unit; ekspor CSV sesuai izin. */
export default async function ParticipantsPage({ params, searchParams }: PageProps<"/corporate/[org]/peserta">) {
  const { org } = await params;
  const sp = await searchParams;
  const q = first(sp.q);
  const status = first(sp.status);
  const page = Math.max(1, Number(first(sp.page)) || 1);
  const qs = new URLSearchParams({ page: String(page), ...(q ? { q } : {}), ...(status ? { status } : {}) });
  const [s, res] = await Promise.all([getSummary(org), corporateApi<Page>(orgPath(org, `/enrollments?${qs}`), `/corporate/${org}/peserta`)]);
  const link = (p: number) => `/corporate/${org}/peserta?${new URLSearchParams({ page: String(p), ...(q ? { q } : {}), ...(status ? { status } : {}) })}`;

  return (
    <PortalCard
      title={`Peserta & Progres (${res.meta.total})`}
      action={
        can(s, "export") ? (
          <a className="lk" href={`/corporate/${org}/peserta/ekspor`}>
            Ekspor CSV
          </a>
        ) : undefined
      }
    >
      <form className="ff flex flex-wrap items-end gap-3" style={{ marginBottom: 14 }}>
        <div className="min-w-[220px] flex-1">
          <label htmlFor="pq">Cari nama</label>
          <input id="pq" name="q" defaultValue={q} placeholder="Nama peserta" />
        </div>
        <div className="min-w-[180px]">
          <label htmlFor="ps">Status</label>
          <select id="ps" name="status" defaultValue={status}>
            <option value="">Semua</option>
            {Object.entries(ENROLLMENT_STATUS).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </div>
        <button className="kb2 px-6! py-3!" type="submit">
          Terapkan
        </button>
      </form>
      {res.data.length === 0 ? (
        <EmptyState text="Belum ada peserta yang disponsori perusahaan. Peserta muncul setelah ditetapkan ke kursi dan batch dijadwalkan." />
      ) : (
        <ul className="sp">
          {res.data.map((e) => (
            <li key={e.id}>
              <span className="spl">
                <b>{e.progressVisible ? <Link href={`/corporate/${org}/peserta/${e.id}`}>{e.name}</Link> : e.name}</b>
                <MetaList
                  compact
                  items={[
                    { icon: "grad", value: e.program },
                    { icon: "cal", value: e.schedule ? `${e.schedule}${e.dates ? ` · ${e.dates}` : ""}` : null },
                    { label: "ID", value: e.employeeId },
                    { value: e.unit },
                    e.employment === "inactive" && { value: "Sudah tidak bekerja di perusahaan" },
                  ]}
                />
              </span>
              <b className="spv">
                <span className={`jst ${e.status === "completed" ? "ok" : e.status === "dropped" || e.status === "failed" ? "no" : "wn"}`}>{ENROLLMENT_STATUS[e.status] ?? e.status}</span>
                <small>
                  {e.attendancePercent !== null ? `Hadir ${e.attendancePercent}%` : e.progressVisible ? "" : "Detail tertutup"}
                  {e.certificateStatus ? ` · Sertifikat ${CERT_STATUS[e.certificateStatus]?.toLowerCase() ?? e.certificateStatus}` : ""}
                </small>
              </b>
            </li>
          ))}
        </ul>
      )}
      {res.meta.lastPage > 1 && (
        <div className="flex justify-between" style={{ marginTop: 14 }}>
          {page > 1 ? <Link href={link(page - 1)}>← Sebelumnya</Link> : <span />}
          <small className="fh">
            Halaman {res.meta.currentPage} dari {res.meta.lastPage}
          </small>
          {page < res.meta.lastPage ? <Link href={link(page + 1)}>Berikutnya →</Link> : <span />}
        </div>
      )}
    </PortalCard>
  );
}
