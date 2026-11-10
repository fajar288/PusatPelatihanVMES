import Icon from "@/components/ui/Icon";

const POINTS = [
  "Instruktur praktisi industri",
  "Pembelajaran berbasis praktik",
  "Sertifikasi dan sertifikat keikutsertaan",
  "Support pasca training",
];

/** div.kbx.why — kotak "Kenapa Pilih PPTI?" di sidebar halaman detail (teks identik dengan website lama). */
export default function WhyBox() {
  return (
    <div className="kbx why">
      <h3>Kenapa Pilih PPTI?</h3>
      {POINTS.map((p) => (
        <div key={p}>
          <Icon name="star" />
          <span>{p}</span>
        </div>
      ))}
    </div>
  );
}
