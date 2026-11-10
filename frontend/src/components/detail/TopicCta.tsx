import Link from "next/link";
import { waLink } from "@/lib/utils";

/** div.kbx.pcd "Tertarik dengan topik ini?" — kotak CTA sidebar single post, teks identik website lama. */
export default function TopicCta({ topic }: { topic: string }) {
  return (
    <div className="kbx pcd" id="kd-sc">
      <h3>Tertarik dengan topik ini?</h3>
      <p className="sdt">Tanyakan jadwal, harga, dan metode belajar langsung ke tim kami.</p>
      <div className="kbt">
        <a className="kb1 wa" href={waLink(`Halo, saya ingin info ${topic}`)} target="_blank" rel="noopener">
          Konsultasi via WhatsApp
        </a>
        <Link className="kb2" href="/jadwal">
          Lihat Jadwal Training
        </Link>
      </div>
    </div>
  );
}
