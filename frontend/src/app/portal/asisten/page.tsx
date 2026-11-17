import Link from "next/link";
import LearningAssistantChat from "@/components/portal/LearningAssistantChat";
import LearningAssistantPanel from "@/components/portal/LearningAssistantPanel";
import { PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { AssistantMessage, AssistantStatus } from "@/lib/portal-types";

export const instant = false;

/** Bila API tidak terjangkau, panel tampil sebagai "Belum tersedia". */
const FALLBACK: AssistantStatus = {
  enabled: false,
  available: false,
  status: "unavailable",
  label: "Belum tersedia",
  message: "Asisten Belajar belum tersedia. Untuk pertanyaan materi, hubungi trainer lewat forum diskusi kelas atau tim admin.",
};

/** /portal/asisten?course=slug&lesson=id - Asisten Belajar: tanya jawab dari materi kelas dengan sumber. */
export default async function PortalAssistantPage({ searchParams }: PageProps<"/portal/asisten">) {
  const { token } = await requireUser("/portal/asisten");
  const sp = await searchParams;
  const course = typeof sp.course === "string" ? sp.course : undefined;
  const lesson = typeof sp.lesson === "string" && /^\d+$/.test(sp.lesson) ? Number(sp.lesson) : undefined;

  const status = await api<{ data: AssistantStatus }>("/me/assistant", { token })
    .then((r) => r.data)
    .catch(() => FALLBACK);
  const history = status.available
    ? await api<{ data: AssistantMessage[] }>("/me/assistant/messages", { token })
        .then((r) => r.data.slice().reverse())
        .catch(() => [] as AssistantMessage[])
    : [];

  return (
    <PortalCard title="Asisten Belajar">
      <p className="fh">
        Asisten Belajar menjawab pertanyaan hanya dari materi kelas yang Anda ikuti dan selalu menyebutkan lesson sumbernya. Bila jawabannya tidak ada di materi, tanyakan ke trainer
        lewat forum diskusi kelas.
      </p>
      {status.available ? <LearningAssistantChat status={status} history={history} initialCourse={course} initialLesson={lesson} /> : <LearningAssistantPanel status={status} />}
      <p className="fh" style={{ marginTop: 14 }}>
        Butuh bantuan lain? Buka <Link href="/portal/belajar">materi belajar</Link> atau hubungi kami lewat halaman <Link href="/portal/bantuan">Bantuan</Link>.
      </p>
    </PortalCard>
  );
}
