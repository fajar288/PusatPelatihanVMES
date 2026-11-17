Kita lanjut ke **Roadmap 5 tahap 5I: AI terukur** (PRD Roadmap 5 §9 Modul F "Measurable AI" dan §9.1 AI Governance). Atasan menyetujui fitur ini pada 2026-11-16. Prompt ini **menggantikan** batasan keputusan nomor 4 di `docs/PROMPT-Roadmap5.md` khusus untuk tahap 5I. Aturan lain di prompt itu tetap berlaku.

## Bacaan wajib sebelum mengubah apa pun

1. `docs/PRD-Roadmap5.md` §8 (KPI), §9, §9.1, §15, §20.11 (batas privasi), §20.15 dan §20.16 (analitik & TNA perusahaan).
2. `task.md`: bagian **5I AI terukur**, **DEPLOY - cPanel**, dan keputusan penyedia di §13.1.
3. Kode yang sudah ada dan wajib dipakai ulang, bukan diduplikasi:
   - Chatbot publik: `config/chatbot.php`, `App\Services\ChatbotService`, `App\Support\Chatbot\{ChatModel, AnthropicChatModel, ChatReply}`, tabel `chat_logs`, palsu `Tests\Fakes\FakeChatModel`.
   - Aturan peserta berisiko: `AnalyticsService::atRisk()` dan perintah `notifications:risk`.
   - Data progres: `MonitoringService` (kehadiran, materi, nilai, praktik, kompetensi), `LmsService`, `CompletionService`.
   - Hasil 5F (master kompetensi, target per posisi, `docs/KPI.md`) dan 5G (tabel `ai_insights`, flag `AI_CHATBOT_ENABLED`, endpoint 503 `ai_unavailable`).
4. **Muat skill `claude-api` sebelum menulis kode Anthropic SDK**, lalu baca bagian PHP-nya. Jangan menebak nama kelas atau parameter SDK. Proyek memakai SDK resmi `anthropic-ai/sdk` (sudah terpasang sejak PR #22).

Jika 5F, 5G, atau 5H belum digabung ke `main`, kerjakan dulu tahap itu sesuai `docs/PROMPT-Roadmap5.md`, baru mulai 5I.

## Aturan kerja (sama seperti Roadmap 5)

- Satu sub-tahap = satu branch + satu PR lewat GitHub API. Gabungkan sendiri setelah CI hijau, lalu lanjut ke sub-tahap berikutnya tanpa menunggu, kecuali di titik "BERHENTI" di bawah.
- Setiap sub-tahap wajib punya migrasi + model + service + tes (termasuk tes skenario governance), Pint, dan untuk frontend `tsc`, `eslint`, `next build`, plus uji end-to-end seperti tahap sebelumnya.
- Perbarui checklist 5I dan LOG PROGRES di `task.md` setiap sub-tahap selesai.
- Halaman baru di portal memakai Tailwind dengan token warna tema (aturan §0.2 `task.md`). Jangan menyentuh tampilan halaman publik.
- Resource Filament baru wajib memakai `NavigationGroup`, label bahasa Indonesia, dan ikon sendiri.

## Keputusan yang mengikat

1. **AI tidak pernah mengambil keputusan resmi.** AI tidak boleh mengubah status kelulusan, nilai, penilaian kompetensi, sertifikat, enrollment, invoice, atau pembayaran (PRD §9.1). Keluaran AI hanya disimpan sebagai *insight* di `ai_insights`, dengan status `draft`, `approved`, `rejected`, atau `expired`. Tulis tes yang membuktikan tidak ada jalur kode dari layanan AI ke tabel-tabel resmi itu.
2. **Mati bawaan, per use case.** Pakai flag global `AI_ENABLED=false` ditambah flag per fitur, misalnya `AI_EARLY_WARNING_ENABLED` dan `AI_RECOMMENDATION_ENABLED`. Fitur yang memanggil Claude juga butuh `ANTHROPIC_API_KEY`. Saat mati, UI menampilkan status "Belum tersedia" dan proses manual tetap berjalan. Tes dan pengembangan lokal **tidak boleh** memanggil API sungguhan. Pakai model palsu.
3. **Pisahkan metode statistik dan LLM.** Early warning, demand forecast, dan peringkat rekomendasi memakai metode statistik atau aturan yang deterministik dan bisa dijelaskan, tanpa LLM. Claude hanya dipakai untuk tugas teks: klasifikasi feedback, Asisten Belajar, narasi corporate insight, dan opsional kalimat alasan rekomendasi.
4. **Privasi dan minimisasi data.**
   - Jangan kirim nama, email, telepon, NIK, atau alamat ke API. Pakai ID internal atau pseudonim.
   - Komentar survei disaring dulu dari email dan nomor telepon sebelum dikirim.
   - Asisten Belajar hanya mengirim isi lesson yang disetujui dan pertanyaan peserta. Riwayat nilai atau data pribadi tidak ikut dikirim.
   - Catat referensi data yang dipakai di `ai_insights`, berupa ID dan hash, bukan salinan data pribadi.
   - Batas privasi perusahaan mengikuti PRD §20.11. Perusahaan hanya melihat insight untuk enrollment yang disponsorinya.
   - Tambahkan paragraf pemrosesan AI ke draf halaman Kebijakan Privasi, lalu tandai sebagai draf yang perlu ditinjau legal.
5. **Pemakaian Claude.**
   - Model bawaan `claude-opus-5-5`, sama dengan chatbot publik, dan bisa diatur per use case lewat env. **Jangan menurunkan model demi biaya tanpa keputusan pemilik proyek.** Cukup sediakan konfigurasinya.
   - Pakai structured output (`output_config.format`) untuk klasifikasi dan narasi berstruktur.
   - Tangani `stop_reason` `refusal` dan pakai server-side fallback seperti `AnthropicChatModel`.
   - Klasifikasi massal memakai Message Batches yang dipantau perintah terjadwal.
   - Asisten Belajar memakai dokumen dengan citations. Citations tidak bisa digabung dengan `output_config.format`.
   - Pakai prompt caching untuk prompt sistem dan materi course yang stabil.
6. **Kendala cPanel.** Di cPanel tidak ada worker yang berjalan terus. Semua job berjalan lewat `schedule:run` dan `queue:work --stop-when-empty` dari cron. Panggilan sinkron di request web harus singkat dan punya timeout. Pekerjaan berat, seperti batch feedback, penghitungan skor, dan forecast, berjalan sebagai perintah terjadwal.
7. **Biaya terkendali.** Setiap use case punya batas harian untuk jumlah panggilan dan token. Simpan `usage` dari setiap respons beserta perkiraan biayanya. Tampilkan pemakaian di halaman admin. Bila batas tercapai, fitur kembali ke aturan atau proses manual dan mencatat kejadiannya.
8. **Terukur sebelum dinyalakan.** Setiap use case punya metrik sesuai tabel PRD §9, baseline, dan perintah `php artisan ai:evaluate {usecase}` yang menghasilkan laporan. Tambahkan juga `php artisan ai:readiness`, yang membandingkan jumlah data riil dengan ambang minimum per use case. Data saat ini masih dummy, jadi evaluasi di PR hanya membuktikan pipeline berjalan. Tulis ini terang-terangan di PR dan di UI ("Data belum cukup").
9. **Tinjauan manusia.**
   - Corporate insight wajib disetujui admin VMES sebelum tampil ke perusahaan.
   - Skor early warning hanya tampil ke staf, trainer, dan perusahaan sponsor, beserta alasannya.
   - Rekomendasi boleh langsung tampil ke member karena risikonya rendah, tetapi tetap dicatat.
   - Setiap keluaran AI di UI diberi label "Dibuat AI" dan tombol umpan balik (membantu atau tidak). Umpan balik ini dipakai sebagai metrik.
10. **Gaya teks keluaran AI.** Berlaku untuk chatbot publik, Asisten Belajar, narasi insight, dan setiap teks AI lain yang tampil ke pengguna.
    - Prompt sistem wajib memuat dua larangan: jangan memakai em dash (U+2014) atau en dash (U+2013), dan jangan menyebut dokumen internal (dokumen perencanaan atau spesifikasi, nomor pasal, kode tahap proyek, nama berkas catatan pengembang). Tulis larangan itu dengan kata-kata, jangan menyertakan karakter dash-nya di prompt.
    - Model tetap bisa melanggar, jadi teks keluaran **selalu** dilewatkan ke `App\Support\Ai\AiText::normalize()` sebelum disimpan (log, `ai_insights`) dan sebelum dikirim ke pengguna. Aturannya deterministik: rentang angka/jam/tanggal jadi `-` tanpa spasi, dash di awal baris jadi butir `- `, pemisah antar kata/kalimat jadi ` - `.
    - Setiap perubahan prompt menaikkan versi prompt (`prompt_version`) supaya log bisa dibandingkan.
    - Tes memakai model palsu (contoh: `Tests\Fakes\FakeChatModel`) yang membalas teks berisi em dash dan en dash, lalu memastikan teks yang tersimpan dan yang dikirim sudah bersih. Contoh: `ChatbotTest::test_reply_is_normalized_without_em_or_en_dash_before_logging_and_sending` dan `tests/Unit/AiTextTest.php`.

## Urutan sub-tahap

**5I.1 Fondasi tata kelola AI** (`feat/ai-foundation`)
- Kontrak model bersama yang memakai ulang `ChatModel`, atau memperluasnya tanpa merusak chatbot publik. Daftarkan use case di `config/ai.php`, berisi flag, model, effort, versi prompt, dan batas biaya.
- Lengkapi `ai_insights` dari 5G: use case, subjek, versi model, versi prompt, referensi data, keluaran JSON, skor atau keyakinan, status, reviewer, waktu tinjauan, dan alasan penolakan. Tambahkan tabel pemakaian token dan biaya.
- Admin Filament grup "AI": daftar insight, antrean tinjauan, pemakaian dan biaya, status flag per use case. Izin baru `ai.review` dan `ai.manage`, dengan perubahan tercatat di `audit_logs`.
- Kerangka `ai:evaluate` dan `ai:readiness`, helper penyaring data pribadi, dan model palsu untuk tes.

**5I.2 Early warning** (`feat/ai-early-warning`)
- Skor risiko 0-100 dan alasan teratas per peserta aktif, dihitung dari kehadiran, progres LMS, nilai kuis dan tugas, praktik di logbook, dan keterlambatan tugas. Pakai model statistik transparan, misalnya regresi logistik sederhana atau skor berbobot yang dikalibrasi dari kelas selesai, dengan aturan `atRisk()` sebagai baseline.
- Hitung harian lewat perintah terjadwal. Tampilkan di portal trainer (progres kelas), admin (Dashboard Eksekutif), dan portal perusahaan untuk peserta bersponsor saja. Picu notifikasi lewat engine 5D tanpa duplikasi dengan `notifications:risk`.
- Evaluasi: precision, recall, dan F1 terhadap hasil kelas yang sudah selesai (lulus atau tidak), dibandingkan dengan baseline aturan.

**5I.3 Rekomendasi training** (`feat/ai-recommendation`)
- Kandidat dari riwayat pelatihan, prasyarat paket, gap kompetensi 5F, dan pola co-enrollment. Peringkatnya deterministik, dan alasannya bisa ditelusuri ke data, misalnya "Anda lulus PLC Dasar" atau "gap SCADA 18 poin".
- Tampil di portal member, dashboard alumni, dan portal perusahaan per karyawan atau posisi. Catat impresi, klik, dan pendaftaran untuk metrik CTR, konversi, dan repeat enrollment.

**5I.4 Survei evaluasi + analisis feedback** (`feat/ai-feedback`)
- Modul survei pasca-kelas belum ada, jadi buat dulu: template pertanyaan (skala + komentar), kirim otomatis saat kelas selesai lewat engine 5D, isi di portal member, dan laporan untuk admin dan trainer. Survei tetap berguna walau AI mati.
- Klasifikasi komentar menjadi tema, sentimen, dan kategori dengan Claude memakai structured output dan Message Batches. Komentar disaring dari data pribadi dulu.
- Sediakan set label manual di admin untuk menghitung F1 dan akurasi. Ringkasan per kelas, trainer, dan program ditinjau admin sebelum dibagikan ke trainer.

**5I.5 Asisten Belajar LMS** (`feat/ai-lms-assistant`)
- Ganti kerangka 5G dengan asisten sungguhan. Jawaban hanya berasal dari materi lesson yang disetujui pada course yang boleh diakses peserta, dengan aturan akses LMS yang sama. Setiap jawaban menyertakan sitasi ke lesson sumber.
- Bila jawabannya tidak ada di materi, asisten mengatakannya dan menawarkan bertanya ke trainer lewat forum yang sudah ada.
- Batas pemakaian per peserta per hari. Metrik: penilaian kualitas jawaban dari umpan balik, cakupan sitasi, dan unresolved rate.

**5I.6 Corporate insight** (`feat/ai-corporate-insight`)
- Ringkasan gap kompetensi dan usulan tindakan per organisasi atau unit, dari data 5F dan hasil pelatihan. Kirim angka agregat, bukan data per orang yang bisa dikenali.
- Wajib disetujui admin VMES sebelum tampil di portal perusahaan. Metrik: acceptance rate dan konversi menjadi training request.

**5I.7 Demand forecast** (`feat/ai-demand-forecast`)
- Prakiraan pendaftar per paket, kategori, dan bulan memakai metode statistik, misalnya seasonal naive atau moving average, lengkap dengan backtest MAE dan MAPE serta rentang ketidakpastian. Tidak memakai LLM.
- Tampil di Dashboard Eksekutif dengan label "Prakiraan".

## BERHENTI dan tanya pemilik proyek bila

- Akan mengaktifkan fitur AI dengan `ANTHROPIC_API_KEY` sungguhan, termasuk untuk uji coba, atau mengubah flag bawaan menjadi aktif.
- Sebuah fitur ternyata butuh mengirim data pribadi melebihi aturan nomor 4.
- Ada keputusan bisnis baru yang memengaruhi skema data dan tidak tertulis di PRD, `task.md`, atau prompt ini. Untuk hal kecil, putuskan sendiri dan tulis asumsinya di PR.
- Hasil evaluasi menunjukkan sebuah use case kalah dari baseline. Laporkan, jangan dipaksakan.

Mulai dengan membaca bahan di atas. Pastikan 5F-5H sudah selesai, lalu kerjakan 5I.1.
