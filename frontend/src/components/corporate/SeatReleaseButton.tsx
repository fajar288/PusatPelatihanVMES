"use client";

import { useTransition } from "react";
import { releaseSeatAction } from "@/lib/actions/corporate";

/** Batalkan peserta di satu kursi — hanya enrollment kursi itu yang berubah (§11). */
export default function SeatReleaseButton({ org, code, seat, label }: { org: string; code: string; seat: number; label: string }) {
  const [pending, start] = useTransition();
  return (
    <button
      type="button"
      className="copyb"
      disabled={pending}
      onClick={() => {
        const reason = window.prompt(`Alasan membatalkan peserta di ${label}:`);
        if (!reason) return;
        start(async () => {
          const r = await releaseSeatAction(org, code, seat, reason);
          if (!r.ok) window.alert(r.message);
        });
      }}
    >
      {pending ? "…" : "Batalkan"}
    </button>
  );
}
