Kita lanjut ke **PRD Roadmap 5 (Enterprise, Integration & Intelligence)** dari atasan. Teks lengkapnya sudah diekstrak ke `docs/PRD-Roadmap5.md`. Baca seluruhnya dulu, terutama §4, §11, §12, §13, §18, dan §20 (Corporate Portal & Enrollment Sponsorship). Lalu baca `task.md` dan kode yang relevan sebelum mengubah apa pun.

## Aturan kerja (sama seperti sebelumnya)

- Satu tahap = satu branch + satu PR lewat GitHub API. Gabungkan sendiri setelah CI hijau, lalu lanjut ke tahap berikutnya tanpa menunggu saya, kecuali di titik "BERHENTI" di bawah.
- Setiap tahap wajib: migrasi + model + service + tes Pest/PHPUnit (termasuk tes skenario PRD), Pint, dan untuk frontend `tsc`, `eslint`, `next build`, plus uji end-to-end (curl + cookie / Edge headless) seperti tahap-tahap sebelumnya.
- Tambahkan bagian **PHASE 5 — Roadmap 5** di `task.md` berisi checklist per tahap (5A–5H) sebelum mulai coding. Perbarui checklist dan LOG PROGRES setiap tahap selesai.
- Halaman baru portal perusahaan memakai Tailwind dengan token warna tema yang sama (aturan §0.2 `task.md`). Jangan menyentuh tampilan halaman publik.
- Semua perubahan akses, enrollment, sponsor, pembayaran, dan sertifikat wajib tercatat di `audit_logs`.

## Keputusan yang mengikat

1. **Keputusan arsitektur PRD §18 tidak boleh dilanggar.** Satu Member per orang. Sponsor disimpan di Enrollment, bukan di Member. Employment Relationship adalah tabel terpisah. Enrollment SELF tidak punya sponsor.
2. **Pembayaran tetap manual** (dikonfirmasi ulang atasan 2026-11-12: transfer bank / Virtual Account statis + unggah bukti + verifikasi finance). Bagian payment gateway di PRD §5 **tidak dikerjakan**. Catat di `task.md` bahwa ini penyimpangan yang disengaja dari PRD. Aturan PRD yang tetap berlaku untuk alur manual: invoice lunas tidak bisa dibayar ulang, status hanya berubah lewat verifikasi finance (bukan klaim peserta), refund punya alasan/penyetuju/waktu/referensi, dan semua perubahan diaudit. **Jangan** memasang Midtrans, Xendit, atau penyedia lain, dan jangan pernah membuat form yang meminta nomor kartu.
3. **WhatsApp ditunda.** Jangan memasang penyedia WA apa pun. Kanal WA cukup antarmuka dengan driver `log`, dimatikan lewat konfigurasi.
4. **AI: chatbot publik sudah ada (nonaktif), asisten belajar baru kerangka.** *(Diperbarui 2026-11-12, pilihan nomor 3.)* Chatbot informasi publik dengan Claude sudah dibangun di PR #22 (SDK `anthropic-ai/sdk`, flag `CHATBOT_ENABLED=false`, hanya data katalog website) dan tetap dipertahankan dalam keadaan nonaktif. Untuk portal member, buat UI chatbot (asisten belajar) dengan status **"Belum tersedia"**: tombol/panel terlihat, input nonaktif, tanpa panggilan ke API AI mana pun, dikendalikan flag terpisah `AI_CHATBOT_ENABLED=false`. Siapkan endpoint backend yang mengembalikan 503 `{"error":{"code":"ai_unavailable"}}` selama flag mati. Asisten belajar tidak boleh mengirim data peserta ke API AI mana pun sampai ada keputusan baru.
5. **Kebijakan yang belum diputuskan dibuat bisa dikonfigurasi** dengan default aman, lalu dicatat di `task.md` sebagai keputusan terbuka:
   - Pembuatan akun organisasi: default hanya admin VMES yang membuat organisasi lalu mengundang Corporate Owner. Simpan sebagai setting agar pendaftaran mandiri perusahaan bisa dibuka nanti.
   - Pajak invoice: setting tarif pajak, default 0% dan baris pajak disembunyikan bila 0.
   - Akses histori setelah resign: setting per organisasi, default mengikuti PRD §20.7. Peserta yang employment-nya INACTIVE hilang dari daftar karyawan aktif dan detail progresnya tidak bisa dibuka lagi, tetapi enrollment sponsored lamanya tetap dihitung di laporan agregat dan status sertifikatnya tetap terlihat.
6. Kolom teks `members.company` dan `registrations.company` boleh tetap ada sebagai data isian, tetapi **tidak boleh dipakai untuk hak akses** perusahaan.

## Celah yang sudah saya temukan (verifikasi sendiri di kode)

- Belum ada entitas Organization, Employment Relationship, Corporate User, Training Request, Seat, atau Sponsorship Transfer.
- `enrollments` belum punya `type` (self/corporate) maupun `sponsor_organization_id`.
- Pendaftaran kolektif membuat enrollment dengan `member_id` bisa kosong (`EnrollmentService::fromRegistration`). Ini melanggar prinsip satu identitas Member. Butuh identity matching.
- Pendaftaran kolektif saat ini mewajibkan nama peserta di awal. PRD meminta request tanpa nama + reserved seat.
- Peran yang ada belum punya peran perusahaan (owner, HR/L&D, supervisor, finance, viewer) maupun organization scope.
- Status pembayaran (`unpaid|submitted|verified|rejected|refunded`) belum punya tabel transaksi, rekonsiliasi, maupun alur refund dengan alasan/penyetuju/referensi.
- Notification Center belum punya kanal WA, penjadwalan, retry policy, status delivered/read, versi template, dan penerima dari pihak perusahaan.
- API publik belum punya endpoint enterprise, klien API yang terikat organisasi, dan header `Idempotency-Key`.
- Analitik belum membedakan corporate vs self-funded, belum ada funnel, cohort, repeat order, AOV, dan analitik perusahaan.
- Kompetensi hanya berupa teks di kriteria rubrik. Belum ada master kompetensi dan target kompetensi per posisi (dibutuhkan untuk competency gap/TNA).

## Urutan tahap

**5A Fondasi data enterprise** (`feat/enterprise-foundation`)
- Tabel `organizations` (profil legal, alamat, industri, kontak, data pajak/invoice, logo, status), `organization_units` (departemen/cost center), `employment_relationships` (member, organisasi, employee ID, departemen, posisi, mulai, selesai, ACTIVE/INACTIVE, sumber), `corporate_users` (user ↔ organisasi + peran perusahaan + batas unit opsional).
- `enrollments.type` + `sponsor_organization_id` dengan aturan R1/R2 ditegakkan di service dan constraint DB. Migrasi backfill: enrollment individu → SELF. Enrollment dari pendaftaran kolektif → CORPORATE dengan organisasi hasil normalisasi nama perusahaan, ditandai `needs_review` agar admin memeriksanya.
- `IdentityMatchingService` sesuai tabel §20.5, termasuk review queue untuk data meragukan. Backfill `member_id` yang kosong lewat service ini.
- Query scope server-side `visibleToOrganization` + Policy. Tidak boleh ada jalan mengganti `organization_id` lewat URL/API (§20.21).
- Alur Sponsorship Transfer (§20.20) dengan audit tak bisa diubah.
- Resign → employment INACTIVE, akses corporate dicabut sesuai kebijakan, member/sertifikat/histori tetap.
- Admin Filament: Organisasi, Employment, Corporate User, Review identitas, Transfer sponsor.
- Tes untuk **setiap baris tabel §11** dan skenario §20.7–20.8.

**5B Corporate Portal MVP** (`feat/corporate-portal`)
- `training_requests` dengan status §4.2 (DRAFT → … → COMPLETED/CANCELLED) dan `request_seats`. Reserved seat tidak membuat Member.
- Quotation dibuat admin VMES. Approval perusahaan opsional per organisasi.
- Penugasan peserta: manual, unggah Excel/CSV (template + laporan galat per baris, pakai openspout yang sudah ada), tautan undangan bertoken dengan verifikasi + consent, dan cari member existing. Semua lewat identity matching, tolak enrollment ganda di batch yang sama.
- Request terjadwal → enrollment CORPORATE dengan sponsor organisasi. Pembatalan satu peserta hanya mengubah enrollment itu.
- Pendaftaran kolektif dari form publik tetap jalan, tetapi admin wajib menautkannya ke organisasi sebelum konfirmasi.
- Frontend `/corporate/*`: login dengan akun yang sama, ringkasan, request, peserta, progres (kehadiran, LMS, penilaian, logbook ringkas tanpa catatan privat trainer), sertifikat (status saja), keuangan, kelola pengguna perusahaan, ekspor sesuai scope. Batas privasi mengikuti §20.11.

**5C Transaksi** (`feat/payment-transactions`)
- Invoice lengkap (item, subtotal, pajak bisa dikonfigurasi, diskon, total, jatuh tempo) untuk pendaftaran individu dan training request.
- Tetap memakai alur manual yang sudah ada (rekening/VA statis, unggah bukti, verifikasi finance). Tidak ada payment gateway.
- `payment_transactions`: setiap bukti yang diunggah dan setiap verifikasi/penolakan tercatat sebagai transaksi (invoice, metode, jumlah, rekening tujuan, tanggal transfer, status, verifikator, waktu). Status lama `unpaid|submitted|verified|rejected|refunded` dipetakan ke Pending/Paid/Failed/Refunded, plus Expired untuk invoice lewat jatuh tempo (job terjadwal).
- Invoice yang sudah lunas tidak bisa menerima bukti baru. Verifikasi ganda tidak membuat enrollment atau notifikasi ganda.
- Halaman rekonsiliasi untuk finance: cocokkan transaksi dengan invoice, tandai selisih nominal (kurang/lebih bayar), dan unggah mutasi rekening CSV opsional untuk dicocokkan.
- Alur refund (alasan, penyetuju, waktu, referensi transfer balik). Dana dikembalikan di luar sistem, sistem hanya mencatat.
- Finance perusahaan bisa melihat invoice organisasinya, mengunggah bukti transfer, dan melihat status verifikasi di portal perusahaan.

**5D Otomasi notifikasi** (`feat/notification-engine`)
- Perluas `NotificationCenter`: katalog event §6 (registration, payment success, H-7/H-1, schedule change, progress risk, completed, certificate issued), penerima per scope termasuk admin perusahaan (hanya untuk enrollment yang disponsori), penjadwalan, retry, status Queued/Sent/Delivered/Failed/Read, versi template, kanal WA driver `log`.

**5E API enterprise** (`feat/api-enterprise`)
- Klien API terikat organisasi. Endpoint §7 dengan scope, rate limit, dan versioning. Header `Idempotency-Key` untuk POST penting. Event webhook untuk domain baru. Perbarui spesifikasi OpenAPI (tes yang memastikan semua route terdokumentasi harus tetap lulus).

**5F Analitik lanjutan** (`feat/advanced-analytics`)
- Tulis `docs/KPI.md` dari definisi §8.1 dan implementasikan persis definisi itu.
- Pemisahan corporate vs self-funded, funnel penjualan corporate, cohort/repeat order, AOV, analitik perusahaan §20.15 di portal corporate. Master kompetensi + target kompetensi per posisi untuk competency gap dasar.

**5G Kerangka chatbot AI** (`feat/ai-chatbot-shell`)
- Chatbot publik (PR #22) tidak diubah. Hanya kerangka sesuai keputusan nomor 4: panel chatbot "Asisten Belajar" di portal member dengan label "Belum tersedia", flag `AI_CHATBOT_ENABLED`, endpoint 503, dan tabel `ai_insights` kosong (use case, versi model, versi prompt, referensi data, reviewer) untuk tata kelola §9.1 nanti. Rekomendasi, early warning berbasis model, dan analisis feedback tidak dikerjakan.

**5H Hardening** (`feat/roadmap5-hardening`)
- Tes keamanan organization scope (IDOR antar perusahaan, ekspor, token yang dicabut), cakupan audit, indeks DB untuk kueri dashboard, dan checklist acceptance criteria §15 + §20.22 di `task.md` dengan status tiap butir.

## BERHENTI dan tanya saya bila

- Ada keputusan bisnis baru yang tidak tertulis di PRD, `task.md`, atau prompt ini dan memengaruhi skema data. Untuk hal kecil, putuskan sendiri dan tulis asumsinya di PR.
- Sebuah aturan PRD bertentangan dengan keputusan yang sudah tercatat di `task.md`.
- Sebelum memasang penyedia pembayaran atau WA apa pun, atau mengaktifkan AI sungguhan.

Mulai dari membaca PRD dan menulis checklist Phase 5 di `task.md`, lalu kerjakan 5A.
