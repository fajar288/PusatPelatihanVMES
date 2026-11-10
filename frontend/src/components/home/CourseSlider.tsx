"use client";

import Link from "next/link";
import { useEffect, useRef } from "react";
import CourseCard from "@/components/cards/CourseCard";
import type { Course } from "@/data/courses";
import { useReducedMotion } from "@/lib/useReducedMotion";

/**
 * section.sec "Kursus" — slider horizontal kartu .cc (port "Slider kursus" theme.main.js):
 * panah geser 1 kartu (lebar + gap 24), wrap awal/akhir, autoplay 4000ms, pause saat hover/focus/touch.
 */
export default function CourseSlider({ courses }: { courses: Course[] }) {
  const sl = useRef<HTMLDivElement>(null);
  const box = useRef<HTMLDivElement>(null);
  const paused = useRef(false);
  const reduce = useReducedMotion();

  const go = (dir: number) => {
    const s = sl.current;
    if (!s) return;
    const c = s.firstElementChild as HTMLElement | null;
    if (!c) return;
    const st = c.offsetWidth + 24;
    const end = s.scrollLeft + s.clientWidth >= s.scrollWidth - 8;
    if (dir > 0 && end) s.scrollTo({ left: 0, behavior: "smooth" });
    else if (dir < 0 && s.scrollLeft <= 8) s.scrollTo({ left: s.scrollWidth, behavior: "smooth" });
    else s.scrollBy({ left: dir * st, behavior: "smooth" });
  };

  useEffect(() => {
    const b = box.current;
    if (!b) return;
    const on = () => (paused.current = true);
    const off = () => (paused.current = false);
    const touchEnd = () => setTimeout(off, 6000);
    (["mouseenter", "focusin", "touchstart"] as const).forEach((e) => b.addEventListener(e, on, { passive: true }));
    (["mouseleave", "focusout"] as const).forEach((e) => b.addEventListener(e, off, { passive: true }));
    b.addEventListener("touchend", touchEnd, { passive: true });
    const t = reduce
      ? null
      : setInterval(() => {
          if (!paused.current && !document.hidden) go(1);
        }, 4000);
    return () => {
      if (t) clearInterval(t);
      (["mouseenter", "focusin", "touchstart"] as const).forEach((e) => b.removeEventListener(e, on));
      (["mouseleave", "focusout"] as const).forEach((e) => b.removeEventListener(e, off));
      b.removeEventListener("touchend", touchEnd);
    };
  }, [reduce]);

  return (
    <section className="sec">
      <div className="wrap">
        <div className="sh">
          <h2>Kursus</h2>
          <Link href="/kursus">Semua kursus</Link>
        </div>
        <div className="sw" ref={box}>
          <button aria-label="Sebelumnya" className="ar l" type="button" onClick={() => go(-1)}>
            &#8592;
          </button>
          <div className="slider" id="h-kursus" ref={sl}>
            {courses.map((c) => (
              <CourseCard key={c.slug} course={c} />
            ))}
          </div>
          <button aria-label="Berikutnya" className="ar r" type="button" onClick={() => go(1)}>
            &#8594;
          </button>
        </div>
      </div>
    </section>
  );
}
