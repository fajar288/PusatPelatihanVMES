import Link from "next/link";
import { CancelDeletionButton, DataDeletionForm } from "@/components/portal/DataDeletionForm";
import { MetaList, PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import { DELETION_PILL, type DeletionInfo, type DeletionIssue } from "@/lib/privacy";

// Halaman per-pengguna (cookie sesi + API terautentikasi) → diblokir di server, tidak di-prerender.
export const instant = false;

function IssueList({ items, tone }: { items: DeletionIssue[]; tone: "no" | "wn" }) {
  return (
    <ul style={{ margin: "10px 0 0", paddingLeft: 20, display: "grid", gap: 6, fontSize: 14, lineHeight: 1.6 }}>
      {items.map((b) => (
        <li key={b.code} style={{ color: tone === "no" ? "#9F1D1D" : "var(--ink)" }}>
          {b.message}
        </li>
      ))}
    </ul>
  );
}

/** /portal/privasi: unduh data pribadi dan ajukan/pantau/batalkan permintaan penghapusan data. */
export default async function PortalPrivacyPage() {
  const { user, token } = await requireUser("/portal/privasi");
  const info = (await api<{ data: DeletionInfo }>("/me/data-deletion", { token })).data;
  const r = info.request;

  return (
    <>
      <PortalCard title="Data Pribadi Saya">
        <p className="fh" style={{ marginTop: 0 }}>
          Unduh salinan data pribadi yang kami simpan untuk akun ini (profil, pendaftaran, pelatihan, invoice, dan sertifikat) dalam format JSON. Penjelasan lengkap ada di{" "}
          <Link href="/kebijakan-privasi">Kebijakan Privasi</Link>.
        </p>
        <a className="kb2" href="/portal/data-saya" download style={{ display: "inline-block", marginTop: 12 }}>
          Unduh Data Saya
        </a>
      </PortalCard>

      {r && (
        <PortalCard title="Status Permintaan Penghapusan">
          <p className="fh" style={{ marginTop: 0 }}>
            <span className={"jst " + DELETION_PILL[r.status]}>{r.statusLabel}</span>
          </p>
          <MetaList
            items={[
              { icon: "cal", label: "Diajukan", value: r.requestedLabel },
              r.status === "pending" && { icon: "clock", label: "Diproses paling lambat", value: r.dueLabel },
              r.status !== "pending" && r.status !== "cancelled" && { icon: "check", label: "Diproses", value: r.processedLabel },
            ]}
          />
          {r.reason && (
            <p className="fh" style={{ marginTop: 10 }}>
              Alasan Anda: {r.reason}
            </p>
          )}
          {r.status === "rejected" && (r.blockers.length > 0 ? <IssueList items={r.blockers} tone="no" /> : r.adminNote && <p className="fh">Alasan: {r.adminNote}</p>)}
          {r.status === "approved" && <p className="fh">Permintaan disetujui dan sedang diproses.</p>}
          {r.cancellable && (
            <>
              <p className="fh" style={{ marginTop: 10 }}>
                Selama belum diproses admin, Anda masih bisa membatalkan permintaan ini.
              </p>
              <CancelDeletionButton />
            </>
          )}
        </PortalCard>
      )}

      <PortalCard title="Hapus Data Pribadi">
        <p className="fh" style={{ marginTop: 0 }}>
          Anda dapat meminta kami menghapus data pribadi akun ini. Permintaan diperiksa admin dan diproses paling lambat {info.deadlineDays} hari. Setelah selesai, akun tidak dapat
          dipakai lagi dan tindakan ini tidak dapat dibatalkan. Unduh dulu salinan data Anda bila masih diperlukan.
        </p>

        <h3 style={{ fontSize: 15, margin: "18px 0 0" }}>Data yang dihapus atau dianonimkan</h3>
        <ul style={{ margin: "8px 0 0", paddingLeft: 20, display: "grid", gap: 6, fontSize: 14, lineHeight: 1.6 }}>
          {info.policy.deleted.map((d) => (
            <li key={d}>{d}</li>
          ))}
        </ul>

        <h3 style={{ fontSize: 15, margin: "18px 0 0" }}>Data yang tetap disimpan</h3>
        <ul style={{ margin: "8px 0 0", paddingLeft: 20, display: "grid", gap: 8, fontSize: 14, lineHeight: 1.6 }}>
          {info.policy.kept.map((k) => (
            <li key={k.item}>
              <b>{k.item}.</b> {k.reason}
            </li>
          ))}
        </ul>

        {info.warnings.length > 0 && (
          <>
            <h3 style={{ fontSize: 15, margin: "18px 0 0" }}>Dampak untuk akun Anda</h3>
            <IssueList items={info.warnings} tone="wn" />
          </>
        )}

        {info.canRequest && info.blockers.length > 0 && (
          <>
            <h3 style={{ fontSize: 15, margin: "18px 0 0" }}>Belum dapat diajukan</h3>
            <p className="fh" style={{ marginTop: 6 }}>
              Permintaan akan ditolak otomatis selama hal berikut belum selesai:
            </p>
            <IssueList items={info.blockers} tone="no" />
            <p className="fh" style={{ marginTop: 10 }}>
              Butuh bantuan? Hubungi admin lewat halaman <Link href="/portal/bantuan">Bantuan</Link>.
            </p>
          </>
        )}

        {info.canRequest && info.blockers.length === 0 && <DataDeletionForm email={user.email} />}
        {!info.canRequest && (
          <p className="fh" style={{ marginTop: 16 }}>
            Anda sudah memiliki permintaan yang sedang diproses.
          </p>
        )}
      </PortalCard>
    </>
  );
}
