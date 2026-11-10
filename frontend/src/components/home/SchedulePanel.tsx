"use client";

import { useState } from "react";
import ScheduleRow from "@/components/cards/ScheduleRow";
import type { Schedule } from "@/data/schedules";

const PER_PAGE = 7; // sama dengan `per = 7` di theme.main.js

/** div.jt — panel "Jadwal Terdekat" dengan paginasi 7 baris/halaman (.jp .pn .pm). */
export default function SchedulePanel({ schedules }: { schedules: Schedule[] }) {
  const [cur, setCur] = useState(1);
  const tot = Math.max(1, Math.ceil(schedules.length / PER_PAGE));

  return (
    <div className="jt">
      <div id="h-jadwal">
        {schedules.map((s, i) => (
          <ScheduleRow key={s.id} s={s} hidden={!(i >= (cur - 1) * PER_PAGE && i < cur * PER_PAGE)} />
        ))}
      </div>
      {tot >= 2 && (
        <div className="jp" id="h-jpg">
          <button className="pn" type="button" disabled={cur < 2} onClick={() => setCur(cur - 1)}>
            &#8592; Sebelumnya
          </button>
          <div className="pnn">
            {Array.from({ length: tot }, (_, i) => i + 1).map((p) => (
              <button
                key={p}
                className={"pm" + (p === cur ? " on" : "")}
                type="button"
                aria-label={`Halaman ${p}`}
                onClick={() => setCur(p)}
              >
                {p}
              </button>
            ))}
          </div>
          <button className="pn" type="button" disabled={cur >= tot} onClick={() => setCur(cur + 1)}>
            Berikutnya &#8594;
          </button>
        </div>
      )}
    </div>
  );
}
