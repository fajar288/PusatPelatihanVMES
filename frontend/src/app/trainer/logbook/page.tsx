import ClassList from "@/components/trainer/ClassList";
import { api } from "@/lib/auth";
import { requireTrainer, type TrainerClass } from "@/lib/trainer";

export const instant = false;

/** /trainer/logbook — Logbook: pilih kelas untuk mencatat pertemuan. */
export default async function TrainerLogbookPage() {
  const { token } = await requireTrainer("/trainer/logbook");
  const classes = (await api<{ data: TrainerClass[] }>("/trainer/classes", { token })).data;
  return (
    <ClassList
      title="Logbook Pertemuan"
      intro="Catat setiap pertemuan: topik, aktivitas, kompetensi, praktik, observasi, kendala, catatan, dan dokumentasi. Tautkan catatan ke peserta, lalu validasi untuk mengunci."
      classes={classes}
      base="/trainer/logbook"
      cta="Buka logbook"
    />
  );
}
