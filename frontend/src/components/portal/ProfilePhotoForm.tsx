"use client";

import { useActionState, useState, useTransition } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { type FormState } from "@/lib/actions/auth";
import { removePhotoAction, uploadPhotoAction } from "@/lib/actions/profile";

/**
 * Foto profil member: pratinjau, unggah/ganti, dan hapus. Foto disimpan privat di server (dipotong persegi, WebP)
 * dan dimuat lewat /portal/foto, jadi hanya terlihat oleh pemilik akun dan admin.
 */
export default function ProfilePhotoForm({ name, photoVersion }: { name: string; photoVersion: string | null }) {
  const [state, action, pending] = useActionState<FormState, FormData>(uploadPhotoAction, { ok: false });
  const [removing, startRemove] = useTransition();
  const [removed, setRemoved] = useState<FormState | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const msg = removed ?? state;

  return (
    <form className="ff pphoto" action={action}>
      <div className="pphoto-row">
        <span className="pphoto-av" aria-hidden="true">
          {preview || photoVersion ? (
            <img src={preview ?? `/portal/foto?v=${encodeURIComponent(photoVersion ?? "")}`} alt="" width={88} height={88} />
          ) : (
            name.slice(0, 1).toUpperCase()
          )}
        </span>
        <div className="pphoto-body">
          <label htmlFor="pp-photo">Foto profil</label>
          <input
            id="pp-photo"
            name="photo"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            required
            aria-describedby="pp-photo-h"
            onChange={(e) => {
              const f = e.target.files?.[0];
              setRemoved(null);
              setPreview((old) => {
                if (old) URL.revokeObjectURL(old);
                return f ? URL.createObjectURL(f) : null;
              });
            }}
          />
          <small id="pp-photo-h" className="fh">
            JPG, PNG, atau WebP maks. 4 MB. Foto dipotong persegi dan hanya terlihat oleh Anda dan admin PPTI.
          </small>
          <div className="pphoto-act">
            <button className="kb1" type="submit" disabled={pending || removing}>
              {pending ? "Mengunggah…" : photoVersion ? "Ganti Foto" : "Unggah Foto"}
            </button>
            {photoVersion && (
              <button
                className="kb2"
                type="button"
                disabled={pending || removing}
                onClick={() =>
                  startRemove(async () => {
                    setPreview(null);
                    setRemoved(await removePhotoAction());
                  })
                }
              >
                {removing ? "Menghapus…" : "Hapus Foto"}
              </button>
            )}
          </div>
        </div>
      </div>
      <FormMessage ok={msg.ok} message={msg.message} />
    </form>
  );
}
