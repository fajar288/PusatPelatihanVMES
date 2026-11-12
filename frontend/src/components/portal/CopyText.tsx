"use client";

import { useState } from "react";

/** Tombol salin kecil (mis. nomor rekening). Spasi/titik/tanda hubung dibuang agar bisa langsung ditempel di m-banking. */
export default function CopyText({ value, label = "Salin" }: { value: string; label?: string }) {
  const [done, setDone] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value.replace(/[\s.-]/g, ""));
      setDone(true);
      window.setTimeout(() => setDone(false), 2000);
    } catch {
      setDone(false);
    }
  };
  return (
    <button type="button" className="copyb" onClick={copy} aria-live="polite">
      {done ? "Tersalin" : label}
    </button>
  );
}
