"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

/** Form nomor sertifikat → /verifikasi/[nomor] (hasil dirender server dari API publik). */
export default function VerifyForm({ initial = "" }: { initial?: string }) {
  const router = useRouter();
  const [value, setValue] = useState(initial);

  const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const n = value.trim().toUpperCase().replace(/\s+/g, "").replace(/\//g, "-");
    if (!n) return;
    router.push(`/verifikasi/${encodeURIComponent(n)}`);
  };

  return (
    <form className="ff" onSubmit={onSubmit}>
      <div className="fg2 vform">
        <div>
          <label htmlFor="v-nomor">Nomor sertifikat</label>
          <input id="v-nomor" name="nomor" required placeholder="PPTI-PST-202611-00001" value={value} onChange={(e) => setValue(e.target.value)} autoComplete="off" spellCheck={false} />
        </div>
        <div style={{ alignSelf: "end" }}>
          <button type="submit" className="btn kb1" style={{ width: "100%" }}>
            Periksa Sertifikat
          </button>
        </div>
      </div>
    </form>
  );
}
