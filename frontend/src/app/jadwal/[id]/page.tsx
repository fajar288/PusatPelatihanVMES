import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import WhyBox from "@/components/detail/WhyBox";
import Icon from "@/components/ui/Icon";
import { type Schedule, scheduleBatchTitle, shortDate } from "@/data/schedules";
import { SITE } from "@/data/site";
import { getSchedule, getSchedules } from "@/lib/cms";
import { abs, JsonLd } from "@/lib/seo";
import { formatRupiah, waLink, waRegisterText } from "@/lib/utils";

// Route detail: `params` dibaca langsung (bukan di dalam <Suspense>) supaya kode asing menghasilkan status 404
// yang benar. Konsekuensinya navigasi ke kode yang belum di-prerender tidak "instan" (cacheComponents).
export const instant = false;

export async function generateStaticParams() {
  return (await getSchedules()).map((s) => ({ id: s.id }));
}

export async function generateMetadata({ params }: PageProps<"/jadwal/[id]">): Promise<Metadata> {
  const { id } = await params;
  const d = await getSchedule(id);
  if (!d) return { title: `Jadwal tidak ditemukan | ${SITE.name}` };
  const s = d.schedule;
  const title = scheduleBatchTitle(s);
  const description = `${s.title} ${s.dateLabel}, ${s.city} (${s.mode}), ${s.durationDays} hari. ${s.statusLabel}.`;
  return { title: `${title} | ${SITE.name}`, description, alternates: { canonical: `/jadwal/${s.id}` }, openGraph: { title, description, type: "website", locale: "id_ID" } };
}

function OtherSchedules({ items, variant }: { items: Schedule[]; variant: "rt-d" | "rt-m" }) {
  if (!items.length) return null;
  return (
    <div className={"rt " + variant}>
      <h2>Jadwal Lainnya</h2>
      <div className="rcg">
        {items.map((s) => (
          <Link className="rc" key={s.id} href={`/jadwal/${s.id}`}>
            <div className="rci" />
            <div className="rcb">
              <h3>{scheduleBatchTitle(s)}</h3>
              <span>
                {shortDate(s.startDate)} • {s.city} <Icon name="arr" />
              </span>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}

/** /jadwal/[id] — detail batch (PRD §5.4). `id` = kode kelas. Data: GET /api/v1/schedules/{code}. */
export default async function JadwalDetailPage({ params }: PageProps<"/jadwal/[id]">) {
  const { id } = await params;
  const d = await getSchedule(id);
  if (!d) notFound();
  const s = d.schedule;
  const course = d.package;
  const title = scheduleBatchTitle(s);
  const full = s.status === "no";
  const others = d.related;
  const chipStatus = s.status === "ok" ? "chp a" : "chp o";

  const eventLd = {
    "@context": "https://schema.org",
    "@type": "EducationEvent",
    name: title,
    description: course?.desc ?? `${s.title} batch ${s.dateLabel}`,
    startDate: s.startDate,
    endDate: s.endDate,
    eventAttendanceMode: s.mode === "online" ? "https://schema.org/OnlineEventAttendanceMode" : s.mode === "hybrid" ? "https://schema.org/MixedEventAttendanceMode" : "https://schema.org/OfflineEventAttendanceMode",
    eventStatus: "https://schema.org/EventScheduled",
    location: s.mode === "online" ? { "@type": "VirtualLocation", url: abs(`/jadwal/${s.id}`) } : { "@type": "Place", name: s.location, address: s.city },
    organizer: { "@type": "Organization", name: SITE.company, url: abs("/") },
    offers: { "@type": "Offer", price: s.price, priceCurrency: "IDR", availability: full ? "https://schema.org/SoldOut" : "https://schema.org/InStock", url: abs(`/jadwal/${s.id}`) },
  };

  return (
    <div className="g-jadwal">
      <JsonLd data={eventLd} />
      <section className="kd" id="kd">
        <div className="wrap">
          <Link className="bk" href="/jadwal" id="kd-back">
            <Icon name="arrl" />
            Kembali ke Jadwal
          </Link>
          <div className="chips" id="kd-chips">
            {s.category && (
              <Link className="chp a" href={`/jadwal?kategori=${encodeURIComponent(s.category)}`}>
                {s.category}
              </Link>
            )}
            <span className="chp">{s.mode}</span>
            <span className="chp">Kode {s.code}</span>
            <span className={chipStatus}>{s.statusLabel}</span>
          </div>
          <h1>{title}</h1>
          <div className="dmeta">
            {s.dateLabel} – {shortDate(s.endDate)}
          </div>
          <p className="rs" id="kd-rs">
            {course?.desc ?? `Batch ${s.title} oleh PPTI - VMES Training Center.`}
          </p>

          <div className="kic">
            <div className="kic1">
              <Icon name="cal" />
              <small>Tanggal</small>
              <b>
                {shortDate(s.startDate)} – {shortDate(s.endDate)}
              </b>
            </div>
            <div className="kic1">
              <Icon name="clock" />
              <small>Waktu</small>
              <b>{s.time || "-"}</b>
            </div>
            <div className="kic1">
              <Icon name="pin" />
              <small>Lokasi</small>
              <b>{s.city}</b>
            </div>
            <div className="kic1">
              <Icon name="users" />
              <small>Kuota</small>
              <b>
                {s.remaining} dari {s.quota} kursi
              </b>
            </div>
          </div>

          <div className="kdg">
            <div className="kdl">
              <section className="kbx">
                <h2>Informasi Kelas</h2>
                <ul className="sp">
                  <li>
                    <span>Program</span>
                    <b>{course ? <Link href={`/kursus/${course.slug}`}>{course.title}</Link> : s.title}</b>
                  </li>
                  <li>
                    <span>Kode kelas</span>
                    <b>{s.code}</b>
                  </li>
                  <li>
                    <span>Tanggal</span>
                    <b>
                      {s.dateLabel} – {shortDate(s.endDate)} ({s.durationDays} hari)
                    </b>
                  </li>
                  <li>
                    <span>Jam</span>
                    <b>{s.time || "-"}</b>
                  </li>
                  <li>
                    <span>Metode</span>
                    <b>{s.mode}</b>
                  </li>
                  <li>
                    <span>Lokasi</span>
                    <b>{s.location || s.city}</b>
                  </li>
                  <li>
                    <span>Trainer</span>
                    <b>{s.trainer || "-"}</b>
                  </li>
                  <li>
                    <span>Kuota</span>
                    <b>
                      {s.quota} peserta (sisa {s.remaining})
                    </b>
                  </li>
                  <li>
                    <span>Status</span>
                    <b>
                      <span className={"jst " + s.status}>{s.statusLabel}</span>
                    </b>
                  </li>
                </ul>
              </section>

              <section className="kbx">
                <h2>Tentang Program</h2>
                <div className="kov">
                  <div className="pb">
                    {course && course.overview.intro.length > 0 ? (
                      <>
                        {course.overview.intro.map((p) => (
                          <p key={p}>{p}</p>
                        ))}
                        {course.overview.learn.length > 0 && (
                          <>
                            <h3>Yang Akan Dipelajari</h3>
                            <ul>
                              {course.overview.learn.map((t) => (
                                <li key={t}>{t}</li>
                              ))}
                            </ul>
                          </>
                        )}
                        {course.overview.requirements.length > 0 && (
                          <>
                            <h3>Persyaratan Peserta</h3>
                            <ul>
                              {course.overview.requirements.map((t) => (
                                <li key={t}>{t}</li>
                              ))}
                            </ul>
                          </>
                        )}
                        <p>
                          <Link href={`/kursus/${course.slug}`}>Lihat detail program {course.title}</Link>
                        </p>
                      </>
                    ) : (
                      <p>Silabus lengkap program {s.title} akan dikirim setelah pendaftaran. Hubungi tim kami untuk informasi materi.</p>
                    )}
                  </div>
                </div>
              </section>

              <OtherSchedules items={others} variant="rt-d" />
            </div>

            <aside className="kdr">
              <div className="ksd" id="kd-side">
                <div className="kbx pcd" id="kd-sc">
                  <p>Biaya training</p>
                  <div className="kpr">{formatRupiah(s.price)}</div>
                  <div className="ksp">
                    <div>
                      <Icon name="cal" />
                      <div>
                        <small>Mulai</small>
                        <b>{s.dateLabel}</b>
                      </div>
                    </div>
                    <div>
                      <Icon name="clock" />
                      <div>
                        <small>Durasi</small>
                        <b>
                          {s.durationDays} Hari{s.time ? ` • ${s.time}` : ""}
                        </b>
                      </div>
                    </div>
                    <div>
                      <Icon name="users" />
                      <div>
                        <small>Sisa kuota</small>
                        <b>
                          {s.remaining} dari {s.quota}
                        </b>
                      </div>
                    </div>
                    {s.certificate && (
                      <div>
                        <Icon name="award" />
                        <div>
                          <small>Sertifikat</small>
                          <b>{s.certificate}</b>
                        </div>
                      </div>
                    )}
                  </div>
                  <div className="kbt">
                    {full ? (
                      <a className="kb1 wa" href={waLink(`Halo, saya ingin masuk daftar tunggu training ${s.title} (${s.dateLabel})`)} target="_blank" rel="noopener">
                        Masuk Daftar Tunggu
                      </a>
                    ) : (
                      <Link className="kb1" href={`/daftar?jadwal=${s.id}`}>
                        Daftar Sekarang
                      </Link>
                    )}
                    <a className="kb2 wa" href={waLink(waRegisterText(s.title, s.dateLabel))} target="_blank" rel="noopener">
                      Tanya via WhatsApp
                    </a>
                  </div>
                </div>
                <WhyBox />
                <OtherSchedules items={others} variant="rt-m" />
              </div>
            </aside>
          </div>
        </div>
      </section>
    </div>
  );
}
