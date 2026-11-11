import ClassList from "@/components/trainer/ClassList";
import { api } from "@/lib/auth";
import { requireTrainer, type TrainerClass } from "@/lib/trainer";

export const instant = false;

/** /trainer/penilaian — Assessment: pilih kelas untuk menilai kompetensi peserta dengan rubrik program. */
export default async function TrainerAssessmentPage() {
  const { token } = await requireTrainer("/trainer/penilaian");
  const classes = (await api<{ data: TrainerClass[] }>("/trainer/classes", { token })).data;
  return (
    <ClassList
      title="Penilaian Kompetensi"
      intro="Nilai setiap peserta berdasarkan rubrik program (pemahaman teori, praktik, troubleshooting, penggunaan alat, penyelesaian tugas, kemandirian). Validasi untuk mengesahkan dan mengunci hasil."
      classes={classes}
      base="/trainer/penilaian"
      cta="Nilai peserta"
    />
  );
}
