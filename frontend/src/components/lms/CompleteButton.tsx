"use client";

import { useState, useTransition } from "react";
import { completeLessonAction } from "@/lib/actions/lms";

/** Tombol "Tandai selesai" untuk lesson non-kuis/tugas. */
export default function CompleteButton({ course, lesson, done }: { course: string; lesson: number; done: boolean }) {
  const [pending, start] = useTransition();
  const [ok, setOk] = useState(done);
  const [err, setErr] = useState<string | null>(null);
  if (ok) return <span className="jst ok">✓ Selesai</span>;
  return (
    <span>
      <button
        type="button"
        className="kb2"
        disabled={pending}
        onClick={() =>
          start(async () => {
            const r = await completeLessonAction(course, lesson);
            if (r.ok) setOk(true);
            else setErr(r.message);
          })
        }
      >
        {pending ? "Menyimpan…" : "Tandai selesai"}
      </button>
      {err && (
        <small className="fh" style={{ color: "#b34200", marginLeft: 8 }}>
          {err}
        </small>
      )}
    </span>
  );
}
