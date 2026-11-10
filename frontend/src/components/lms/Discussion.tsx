"use client";

import { useActionState, useState } from "react";
import { FormMessage } from "@/components/auth/AuthShell";
import { type FormState } from "@/lib/actions/auth";
import { postDiscussionAction } from "@/lib/actions/lms";
import type { DiscussionPostInfo } from "@/lib/lms";

/** Forum / tanya jawab course (opsional per lesson): daftar thread + balasan + form kirim. */
export default function Discussion({ course, lesson, posts, back }: { course: string; lesson?: number; posts: DiscussionPostInfo[]; back: string }) {
  const [state, action, pending] = useActionState<FormState, FormData>(postDiscussionAction, { ok: false });
  const [replyTo, setReplyTo] = useState<number | null>(null);

  const form = (parent: number | null) => (
    <form className="ff ldisc-f" action={action} key={parent ?? "root"}>
      <input type="hidden" name="course" value={course} />
      {lesson !== undefined && <input type="hidden" name="lesson" value={lesson} />}
      {parent !== null && <input type="hidden" name="parent" value={parent} />}
      <input type="hidden" name="back" value={back} />
      <div>
        <label htmlFor={`disc-${parent ?? "root"}`}>{parent === null ? "Ajukan pertanyaan / diskusi" : "Balasan"}</label>
        <textarea id={`disc-${parent ?? "root"}`} name="body" rows={3} required placeholder={parent === null ? "Tulis pertanyaan untuk trainer atau peserta lain…" : "Tulis balasan…"} />
      </div>
      <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
        <button className="kb1" type="submit" disabled={pending} style={{ width: "auto" }}>
          {pending ? "Mengirim…" : "Kirim"}
        </button>
        {parent !== null && (
          <button type="button" className="kb2" style={{ width: "auto" }} onClick={() => setReplyTo(null)}>
            Batal
          </button>
        )}
      </div>
    </form>
  );

  return (
    <div className="ldisc">
      <FormMessage ok={state.ok} message={state.message} />
      {posts.length === 0 && <p className="fh">Belum ada diskusi. Jadilah yang pertama bertanya.</p>}
      {posts.map((p) => (
        <article key={p.id} className="ldisc-p">
          <header>
            <b>{p.author}</b>
            {p.isTrainer && <span className="jst ok">Trainer</span>}
            {p.isPinned && <span className="jst wn">Disematkan</span>}
            <time>{p.createdLabel}</time>
          </header>
          <p>{p.body}</p>
          {p.replies.map((r) => (
            <div key={r.id} className="ldisc-r">
              <header>
                <b>{r.author}</b>
                {r.isTrainer && <span className="jst ok">Trainer</span>}
                <time>{r.createdLabel}</time>
              </header>
              <p>{r.body}</p>
            </div>
          ))}
          {replyTo === p.id ? (
            form(p.id)
          ) : (
            <button type="button" className="lk" onClick={() => setReplyTo(p.id)}>
              Balas
            </button>
          )}
        </article>
      ))}
      {replyTo === null && form(null)}
    </div>
  );
}
