"use client";

import { useActionState } from "react";
import { checkCertificateNameAction, type NameCheckState } from "@/lib/actions/verify";

/** Pencocokan nama untuk sertifikat yang nama penerimanya disamarkan atas permintaan pemiliknya. */
export default function NameCheckForm({ number }: { number: string }) {
  const [state, action, pending] = useActionState<NameCheckState, FormData>(checkCertificateNameAction.bind(null, number), { done: false });
  return (
    <form className="ff" action={action} style={{ marginTop: 18, gap: 8 }}>
      <label htmlFor="vc-name">Cocokkan nama yang tertera di sertifikat</label>
      <div style={{ display: "flex", flexWrap: "wrap", gap: 10 }}>
        <input id="vc-name" name="name" required minLength={2} maxLength={160} autoComplete="off" defaultValue={state.name ?? ""} style={{ flex: "1 1 220px", minWidth: 0, width: "auto" }} />
        <button className="kb2" type="submit" disabled={pending} style={{ flex: "none", padding: "10px 22px", cursor: "pointer", fontFamily: "inherit" }}>
          {pending ? "Memeriksa…" : "Cocokkan"}
        </button>
      </div>
      <p className="fh" role="status" aria-live="polite" style={{ margin: 0 }}>
        {state.done ? (
          <span className={"jst " + (state.matches ? "ok" : "no")}>{state.matches ? "Nama cocok dengan penerima sertifikat ini" : "Nama tidak cocok"}</span>
        ) : (
          (state.message ?? "Huruf besar/kecil, spasi ganda, dan tanda baca diabaikan.")
        )}
      </p>
    </form>
  );
}
