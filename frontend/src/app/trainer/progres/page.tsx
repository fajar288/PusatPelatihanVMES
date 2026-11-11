import ClassList from "@/components/trainer/ClassList";
import { api } from "@/lib/auth";
import { requireTrainer, type TrainerClass } from "@/lib/trainer";

export const instant = false;

/** /trainer/progres — Progress overview: pilih kelas untuk melihat indikator lengkap tiap peserta. */
export default async function TrainerProgressPage() {
  const { token } = await requireTrainer("/trainer/progres");
  const classes = (await api<{ data: TrainerClass[] }>("/trainer/classes", { token })).data;
  return (
    <ClassList
      title="Progres Peserta"
      intro="Gambaran lengkap tiap peserta: kehadiran, penyelesaian materi, nilai kuis/tugas, praktik (dari logbook), pencapaian kompetensi, dan catatan trainer."
      classes={classes}
      base="/trainer/progres"
      cta="Lihat progres"
    />
  );
}
