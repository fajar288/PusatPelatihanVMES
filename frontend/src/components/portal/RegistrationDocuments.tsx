"use client";

import { useActionState } from "react";
import { FieldError, FormMessage } from "@/components/auth/AuthShell";
import { PortalCard } from "@/components/portal/ui";
import { type RegistrationState, uploadRegistrationDocumentsAction } from "@/lib/actions/registration";
import type { RegistrationInfo } from "@/lib/portal-types";

/**
 * Dokumen pendukung pendaftaran di portal member: daftar nama berkas yang sudah dikirim dan form tambah dokumen
 * selama pendaftaran belum terkonfirmasi. Berkasnya sendiri disimpan privat dan hanya dibuka tim admin.
 */
export default function RegistrationDocuments({ r }: { r: RegistrationInfo }) {
  const [state, action, pending] = useActionState<RegistrationState, FormData>(uploadRegistrationDocumentsAction, { ok: false });
  const docs = state.registration?.documents ?? r.documents ?? [];
  const max = r.documentsMax ?? 5;
  const canAdd = (r.documentsAccepted ?? false) && docs.length < max;
  if (docs.length === 0 && !r.documentsAccepted) return null;

  return (
    <PortalCard title="Dokumen pendukung">
      {docs.length > 0 ? (
        <ul className="sp">
          {docs.map((d, i) => (
            <li key={`${d.name}-${i}`}>
              <span>Dokumen {i + 1}</span>
              <b>{d.name}</b>
            </li>
          ))}
        </ul>
      ) : (
        <p className="fh">Belum ada dokumen. Tambahkan surat tugas, identitas, atau berkas lain yang diminta admin.</p>
      )}
      {canAdd ? (
        <form className="ff" action={action} style={{ marginTop: 14 }}>
          <input type="hidden" name="number" value={r.number} />
          <input type="hidden" name="existing" value={docs.length} />
          <div>
            <label htmlFor={`rd-file-${r.number}`}>Tambah dokumen</label>
            <input
              id={`rd-file-${r.number}`}
              name="documents"
              type="file"
              multiple
              required
              accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,application/pdf,image/jpeg,image/png,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
              aria-describedby={`rd-file-h-${r.number}`}
            />
            <small id={`rd-file-h-${r.number}`} className="fh">
              PDF, JPG, PNG, DOC, atau DOCX, masing-masing maks. 4 MB. Sisa {max - docs.length} dari {max} berkas.
            </small>
            <FieldError errors={state.errors} name="documents" />
          </div>
          <FormMessage ok={state.ok} message={state.message} />
          <button className="kb1" type="submit" disabled={pending}>
            {pending ? "Mengunggah…" : "Unggah Dokumen"}
          </button>
        </form>
      ) : (
        r.documentsAccepted && <p className="fh">Batas {max} dokumen sudah tercapai. Hubungi admin bila perlu mengganti berkas.</p>
      )}
    </PortalCard>
  );
}
