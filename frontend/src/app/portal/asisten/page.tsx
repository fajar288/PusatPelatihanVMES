import Link from "next/link";
import LearningAssistantPanel from "@/components/portal/LearningAssistantPanel";
import { PortalCard } from "@/components/portal/ui";
import { api, requireUser } from "@/lib/auth";
import type { AssistantStatus } from "@/lib/portal-types";

export const instant = false;

/** Bila API tidak terjangkau, panel tetap tampil sebagai "Belum tersedia". */
const FALLBACK: AssistantStatus = {
  enabled: false,
  available: false,
  status: "unavailable",
  label: "Belum tersedia",
  message: "Asisten Belajar sedang disiapkan. Untuk pertanyaan materi, hubungi trainer lewat forum diskusi kelas atau tim admin.",
};

/** /portal/asisten — Asisten Belajar (Roadmap 5 tahap 5G): kerangka berlabel "Belum tersedia", input nonaktif, tanpa panggilan AI. */
export default async function PortalAssistantPage() {
  const { token } = await requireUser("/portal/asisten");
  const status = await api<{ data: AssistantStatus }>("/me/assistant", { token })
    .then((r) => r.data)
    .catch(() => FALLBACK);

  return (
    <PortalCard title="Asisten Belajar">
      <p className="fh">
        Asisten Belajar nantinya menjawab pertanyaan berdasarkan materi kelas yang Anda ikuti, lengkap dengan rujukan ke lesson sumbernya. Saat ini fitur masih disiapkan, jadi
        belum ada pertanyaan yang diproses.
      </p>
      <LearningAssistantPanel status={status} />
      <p className="fh" style={{ marginTop: 14 }}>
        Butuh bantuan sekarang? Buka <Link href="/portal/belajar">materi belajar</Link> atau hubungi kami lewat halaman <Link href="/portal/bantuan">Bantuan</Link>.
      </p>
    </PortalCard>
  );
}
