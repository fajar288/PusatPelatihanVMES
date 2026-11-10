PPTI VMES
INTEGRATED TRAININGMANAGEMENT SYSTEM
PRODUCT REQUIREMENTS DOCUMENT (PRD)
Dokumen kebutuhan produk untuk platform digital terintegrasiwebsite, training management, LMS, sertifikasi, dan alumni
PT. PUSAT PELATIHAN TEKNOLOGI INDUSTRIVMES TRAINING CENTER
Versi 1.0  |  3 November 2026  |  Dokumen Usulan

# KONTROL DOKUMEN

| Elemen | Keterangan |
|---|---|
| Nama Produk | PPTI VMES Integrated Training Management System (ITMS) |
| Organisasi | PT. Pusat Pelatihan Teknologi Industri (PPTI) VMES Training Center |
| Jenis Dokumen | Product Requirements Document |
| Versi | 1.0 |
| Status | Usulan untuk validasi kebutuhan dan perencanaan pengembangan |
| Platform | Web-based responsive application |
| Pengguna Utama | Publik, peserta, alumni, trainer, assessor, administrator, manajemen |


# RINGKASAN EKSEKUTIF
PPTI VMES ITMS dirancang sebagai satu platform digital untuk mengelola seluruh siklus layanan pelatihan, mulai dari pengenalan program dan pendaftaran, pengelolaan kelas, pembelajaran online, pemantauan perkembangan peserta, penerbitan sertifikat, hingga pengelolaan alumni.
Sistem menggabungkan empat kapabilitas utama: Corporate Website & CMS, Training Management System (TMS), Learning Management System (LMS), serta Alumni & Certification Management. Seluruh modul menggunakan basis data dan identitas pengguna yang terintegrasi.
Prinsip desain utamanya adalah satu akun untuk setiap orang, satu sumber data untuk setiap aktivitas pelatihan, serta pemisahan yang jelas antara progres belajar, hasil evaluasi, dan pengakuan kompetensi.

# DAFTAR ISI
1. Pendahuluan dan tujuan
2. Ruang lingkup produk
3. Pengguna dan hak akses
4. Arsitektur dan ekosistem
5. Kebutuhan fungsional per modul
6. Alur bisnis utama
7. Struktur menu
8. Model data tingkat tinggi
9. Kebutuhan non-fungsional
10. Rekomendasi teknologi
11. Roadmap pengembangan
12. Kriteria penerimaan
13. Risiko dan keputusan yang perlu ditetapkan
14. Glosarium

# 1. PENDAHULUAN DAN TUJUAN

## 1.1 Latar Belakang
PPTI VMES Training Center membutuhkan platform yang mampu menghubungkan kegiatan pemasaran, administrasi, pelaksanaan pelatihan, pembelajaran digital, pemantauan kompetensi, sertifikasi, dan layanan alumni. Pengelolaan yang tersebar pada berbagai aplikasi atau dokumen dapat menimbulkan duplikasi data, keterlambatan informasi, serta kesulitan memantau perjalanan peserta.
ITMS menjadi pusat operasional digital agar informasi paket, jadwal, peserta, trainer, materi, progres, dan sertifikat saling terhubung.

## 1.2 Tujuan Produk
Menyediakan website resmi sebagai pusat informasi dan pemasaran program pelatihan.
Mengintegrasikan katalog paket, jadwal, pendaftaran, kelas, dan administrasi peserta.
Menyediakan LMS untuk materi e-book, video, kuis, tugas, dan evaluasi.
Memungkinkan trainer mengisi kehadiran, logbook, dan penilaian perkembangan peserta.
Menyediakan dashboard peserta dan alumni untuk memantau pelatihan serta sertifikat.
Mengelola penerbitan dan verifikasi sertifikat sesuai kewenangan penerbit.
Menyediakan data dan laporan bagi administrator serta manajemen.

## 1.3 Prinsip Produk
Single member identity: satu orang menggunakan satu akun untuk seluruh pelatihan yang diikuti.
Modular: fitur dikembangkan per domain, tetapi terhubung dalam satu platform.
Traceable: aktivitas penting memiliki riwayat dan waktu pencatatan.
Mobile responsive: halaman dapat digunakan melalui desktop, tablet, dan ponsel.
Competency-aware: progres konten tidak disamakan dengan penguasaan kompetensi praktik.

# 2. RUANG LINGKUP PRODUK

| Area | Cakupan |
|---|---|
| Public Website & CMS | Landing page, profil, katalog, jadwal, galeri, berita, artikel, testimoni, mitra, principal, kontak. |
| Training Management System | Paket, kurikulum, jadwal, kelas, pendaftaran, peserta, kehadiran, trainer, pembayaran. |
| Learning Management System | Course, modul, lesson, e-book, video, quiz, tugas, diskusi, learning progress. |
| Progress & Logbook | Catatan pertemuan, aktivitas praktik, observasi, penilaian, catatan trainer, ringkasan progres. |
| Certificate Management | Template, nomor sertifikat, penerbitan PDF, QR, verifikasi, riwayat. |
| Member & Alumni | Profil, nomor member, riwayat pelatihan, sertifikat, pendaftaran program baru. |
| Management & Analytics | Dashboard, laporan peserta, kelas, pendapatan, LMS, trainer, sertifikat. |
| Integrasi | Email, WhatsApp API, payment gateway, object storage, video streaming. |


## 2.1 Di Luar Cakupan Tahap Awal
Marketplace terbuka untuk penyedia pelatihan pihak ketiga.
Aplikasi native Android dan iOS. Website dirancang responsif dan dapat dipertimbangkan sebagai PWA.
Payroll dan HRIS perusahaan secara penuh.
Penerbitan otomatis sertifikat kompetensi oleh sistem tanpa proses lembaga sertifikasi berwenang.
AI tutor atau rekomendasi pembelajaran tingkat lanjut sebelum data pembelajaran memadai.

# 3. PENGGUNA DAN HAK AKSES

| Role | Tanggung Jawab dan Akses Utama |
|---|---|
| Super Admin | Konfigurasi seluruh sistem, akun, role, permission, integrasi, dan audit. |
| Administrator | Mengelola konten, paket, jadwal, peserta, kelas, dan data operasional. |
| Management | Melihat dashboard, laporan, indikator bisnis, dan performa pelatihan. |
| Marketing | Mengelola prospek, informasi program, promosi, dan komunikasi pendaftar. |
| Finance | Mengelola invoice, pembayaran, verifikasi transaksi, dan laporan keuangan pelatihan. |
| Training Coordinator | Mengelola kelas, penempatan peserta, jadwal, trainer, dan validasi penyelesaian. |
| Trainer | Mengelola materi kelas, kehadiran, tugas, penilaian, logbook, dan progres peserta. |
| Assessor | Mengelola asesmen sesuai penugasan dan kewenangan yang diberikan. |
| Student | Mengakses kelas aktif, materi, tugas, hasil, dan progres pembelajaran. |
| Alumni | Mengakses riwayat pelatihan, sertifikat, profil member, dan katalog pelatihan baru. |
| Public Visitor | Melihat informasi publik dan mengirim pendaftaran. |

Hak akses perlu dibatasi berdasarkan role, kelas, dan kepemilikan data. Trainer hanya dapat mengakses kelas yang ditugaskan, sedangkan peserta hanya dapat melihat data miliknya sendiri.

# 4. ARSITEKTUR DAN EKOSISTEM
Arsitektur awal direkomendasikan menggunakan modular monolith: satu aplikasi backend dengan modul domain yang terpisah secara logis. Pendekatan ini menjaga implementasi tetap sederhana pada tahap awal dan masih memungkinkan pemisahan layanan ketika skala sistem meningkat.
Gambar 1. Arsitektur konseptual PPTI VMES ITMS.

## 4.1 Lapisan Sistem

| Lapisan | Fungsi |
|---|---|
| Channel / Portal | Antarmuka publik, peserta, alumni, trainer, assessor, administrator, dan manajemen. |
| Business Modules | CMS, TMS, LMS, progress-logbook, sertifikat, alumni, finance, dan reporting. |
| Core Services | Autentikasi, RBAC, notifikasi, API, audit log, dan pengelolaan file. |
| Data Layer | Database relasional, object storage, penyimpanan video, dan backup. |
| External Services | Email, WhatsApp Business API, payment gateway, dan layanan verifikasi bila diperlukan. |


## 4.2 Ekosistem Perjalanan Peserta
Gambar 2. Siklus peserta dari pengunjung hingga menjadi alumni dan mengikuti program lanjutan.

## 4.3 Prinsip Integrasi Data
Paket pelatihan merupakan produk/program yang dapat dijadwalkan berulang kali.
Jadwal yang dibuka untuk pendaftaran direpresentasikan sebagai kelas atau batch.
Pendaftaran mencatat minat dan proses administrasi; enrollment mencatat keikutsertaan resmi dalam kelas.
Learning progress menyimpan aktivitas konsumsi materi digital, sedangkan logbook menyimpan observasi trainer.
Sertifikat diterbitkan berdasarkan aturan kelulusan dan otorisasi yang ditetapkan.

# 5. KEBUTUHAN FUNGSIONAL PER MODUL

## 5.1 Public Website & Landing Page
Tujuan: Menyediakan identitas digital dan sarana pemasaran.
Homepage dengan hero section, CTA, keunggulan, kategori, program unggulan, jadwal terdekat, mitra, testimoni, berita, dan kontak.
Halaman profil, visi-misi, fasilitas, trainer, layanan, dan informasi kerja sama.
Katalog program dengan pencarian, filter kategori, level, metode, dan jadwal.
Halaman detail program berisi tujuan, kompetensi, kurikulum, durasi, persyaratan, harga, jadwal, trainer, dan tombol daftar.
Kontak, formulir konsultasi, tombol WhatsApp, dan tautan media sosial.

## 5.2 Content Management System (CMS)
Tujuan: Memungkinkan tim mengelola konten tanpa mengubah kode.
CRUD galeri foto/video, kategori, tanggal kegiatan, deskripsi, dan urutan tampilan.
CRUD berita dan artikel dengan judul, slug, thumbnail, isi, kategori, tag, penulis, status, dan metadata SEO.
CRUD testimoni dengan nama, foto, instansi, program, isi, rating, dan persetujuan publikasi.
CRUD mitra industri, principal, partner sertifikasi, logo, deskripsi, website, kategori, dan status kerja sama.
Pengelolaan halaman statis, menu navigasi, banner, footer, dan informasi kontak.

## 5.3 Training Program Management
Tujuan: Mengelola paket pelatihan yang ditawarkan.
Data paket: kode, nama, kategori, deskripsi, tujuan, kompetensi, persyaratan, durasi, jam pelajaran, level, metode, harga, kuota, gambar, dan status.
Kurikulum per paket: unit/modul, topik, durasi, metode, dan hasil belajar.
Jenis program: reguler, in-house, private, online, blended, custom perusahaan, dan bundling.
Pengaturan jenis sertifikat yang mungkin diperoleh dan persyaratan penyelesaiannya.
Status paket: draft, published, archived.

## 5.4 Training Schedule & Class Management
Tujuan: Mengelola pelaksanaan setiap paket dalam batch atau kelas.
Jadwal berisi paket, kode kelas, tanggal mulai-selesai, jam, lokasi, metode, kuota, trainer, dan status.
Satu paket dapat memiliki banyak jadwal; satu jadwal dapat memiliki banyak peserta.
Kalender pelatihan, daftar kelas, kapasitas, daftar tunggu, dan riwayat pelaksanaan.
Status kelas: draft, registration open, registration closed, confirmed, ongoing, completed, cancelled.
Perubahan jadwal harus mencatat alasan dan mengirim notifikasi kepada pihak terdampak.

## 5.5 Online Registration & Participant Management
Tujuan: Mengelola calon peserta hingga menjadi peserta resmi.
Form pendaftaran individu dan pendaftaran kolektif perusahaan.
Nomor registrasi otomatis, pilihan paket dan kelas, data identitas, instansi, jabatan, kontak, dan dokumen pendukung.
Status: pending, under review, waiting for payment, payment verification, confirmed, rejected, cancelled.
Verifikasi data, pembayaran, kuota, dan konfirmasi peserta.
Konversi pendaftar menjadi member dan enrollment ke kelas setelah persyaratan terpenuhi.

## 5.6 Member & Alumni Management
Tujuan: Menyediakan identitas digital yang berkelanjutan.
Satu akun untuk peserta yang mengikuti beberapa pelatihan.
Profil, foto, nomor member, instansi, pekerjaan, pendidikan, preferensi komunikasi, dan status keanggotaan.
Riwayat pelatihan, sertifikat, nilai, pembayaran, dan status alumni.
Dashboard alumni untuk mengunduh sertifikat, melihat histori, memperbarui profil, dan mendaftar program baru.
Status member: registered, active student, alumni, inactive.

## 5.7 Trainer & Assessor Management
Tujuan: Mengelola profil, penugasan, dan aktivitas pengajar.
Profil trainer: identitas, foto, kontak, keahlian, pendidikan, pengalaman, sertifikasi, portofolio, dan status aktif.
Penugasan trainer ke kelas, jadwal mengajar, dan daftar peserta.
Trainer mengelola materi, kehadiran, tugas, penilaian, logbook, dan laporan kelas.
Assessor mendapat akses terbatas pada asesmen yang ditugaskan.
Riwayat penugasan dan evaluasi trainer.

## 5.8 Learning Management System (LMS)
Tujuan: Menyediakan pembelajaran online dengan pola course-based seperti platform kursus digital.
Struktur: Program → Kelas → Course → Module → Lesson → Learning Resources.
Materi e-book/dokumen: PDF, PPT, DOC, XLS, dan file pendukung dengan viewer serta izin unduh.
Video learning: streaming, thumbnail, durasi, deskripsi, urutan, resume, dan tracking progres.
Quiz, pre-test, post-test, tugas, unggah jawaban, penilaian, forum, dan tanya jawab.
Progress tracking: materi dibuka, video ditonton, modul selesai, tugas dikumpulkan, kuis dikerjakan, dan nilai.
Pengaturan akses berdasarkan enrollment, periode kelas, dan kebijakan materi.

## 5.9 Training Logbook & Progress Monitoring
Tujuan: Menggabungkan aktivitas pembelajaran dengan catatan perkembangan individual.
Logbook per pertemuan: tanggal, topik, aktivitas, kompetensi, praktik, observasi, kendala, catatan trainer, dan dokumentasi.
Penilaian individual: pemahaman teori, praktik, troubleshooting, penggunaan alat, penyelesaian tugas, dan kemandirian.
Rubrik penilaian dapat disesuaikan dengan kompetensi tiap program.
Progress overview menampilkan kehadiran, penyelesaian materi, nilai, praktik, pencapaian kompetensi, dan catatan trainer.
Trainer menyimpan dan mengunci catatan yang telah divalidasi; perubahan setelah validasi tercatat pada audit log.

## 5.10 Certificate Management
Tujuan: Mengelola penerbitan, penyimpanan, dan pemeriksaan sertifikat.
Template sertifikat, penomoran unik, nama peserta, program, tanggal, penerbit, penandatangan, dan masa berlaku bila relevan.
Generate PDF, QR Code, halaman verifikasi, preview, unduh, dan riwayat penerbitan.
Status: draft, pending validation, issued, revoked, replaced.
Penerbitan didasarkan pada aturan kelulusan dan persetujuan pejabat berwenang.
Sertifikat pelatihan/keikutsertaan dibedakan dari sertifikat kompetensi yang diterbitkan melalui lembaga berwenang.

## 5.11 Finance & Payment
Tujuan: Mendukung administrasi transaksi pendaftaran.
Invoice, nomor tagihan, biaya, diskon, kode promo, termin, dan tanggal jatuh tempo.
Transfer bank dan unggah bukti pembayaran; verifikasi oleh finance.
Riwayat transaksi, status pembayaran, pengembalian dana bila kebijakan mendukung, dan laporan pendapatan.
Payment gateway dapat diintegrasikan setelah penyedia dan kebutuhan transaksi ditentukan.

## 5.12 Notification & Communication
Tujuan: Menyampaikan informasi sesuai peristiwa sistem.
Notifikasi pendaftaran, pembayaran, perubahan jadwal, pengingat kelas, materi baru, tugas, hasil, progres, dan sertifikat.
Kanal: notifikasi aplikasi, email, dan WhatsApp melalui API resmi.
Template pesan, preferensi pengguna, status terkirim/gagal, dan log notifikasi.

## 5.13 Management Dashboard & Analytics
Tujuan: Menyediakan informasi untuk pengendalian operasional.
Statistik pendaftar, peserta aktif, alumni, kelas berjalan, kelas selesai, kapasitas, dan program populer.
Pendapatan per program/periode, invoice, pembayaran, dan piutang.
Progres LMS, completion rate, hasil evaluasi, kehadiran, dan peserta berisiko tertinggal.
Jumlah trainer, penugasan, aktivitas mengajar, dan evaluasi.
Jumlah sertifikat terbit dan status penerbitan.
Filter periode, kategori, paket, kelas, trainer, dan status.

# 6. ALUR BISNIS UTAMA

## 6.1 Dari Pengunjung Menjadi Alumni
Pengunjung menemukan program melalui website.
Pengunjung membuka detail paket dan memilih jadwal yang tersedia.
Pengunjung mengisi formulir pendaftaran dan memperoleh nomor registrasi.
Administrator memeriksa data dan finance memverifikasi pembayaran sesuai kebijakan.
Sistem membuat atau mengaktifkan akun member.
Training Coordinator memasukkan peserta ke kelas (enrollment).
Peserta mengikuti pelatihan, mengakses LMS, dan tercatat kehadirannya.
Trainer mengisi logbook dan penilaian individual.
Training Coordinator memvalidasi penyelesaian pelatihan.
Sertifikat diterbitkan sesuai jenis dan kewenangan penerbit.
Status peserta diperbarui menjadi alumni; riwayat tetap tersimpan.
Alumni dapat mendaftar program lanjutan menggunakan akun yang sama.

## 6.2 Alur Pembelajaran dan Monitoring
Peserta login dan membuka My Training.
Peserta memilih kelas aktif dan membuka modul pembelajaran.
Peserta membaca e-book, menonton video, mengerjakan kuis, dan mengumpulkan tugas.
Sistem mencatat aktivitas serta progres LMS.
Trainer mengisi kehadiran, logbook pertemuan, dan penilaian praktik.
Sistem menyajikan progress overview berdasarkan data yang sudah dicatat.
Peserta melihat progres dan umpan balik; trainer tetap menjadi pihak yang mengesahkan hasil penilaian.

## 6.3 Visual Siklus Layanan

# 7. STRUKTUR MENU APLIKASI

| Area | Menu Utama |
|---|---|
| Public Website | Home; About; Training Programs; Training Schedule; News; Articles; Gallery; Partners; Principals; Testimonials; Contact; Register; Login. |
| Admin | Dashboard; CMS; Training Categories; Packages; Curriculum; Schedules; Classes; Registrations; Participants; Alumni; Trainers; LMS; Logbook; Certificates; Finance; Partners; Users; Reports; Settings. |
| Trainer | Dashboard; My Classes; Participants; Materials; Attendance; Assignments; Quiz Results; Logbook; Progress; Assessment; Reports; Profile. |
| Student / Alumni | Dashboard; Profile; My Training; My Learning; My Progress; Assignments; Results; My Certificates; Training Catalog; Registration History; Payment History; Notifications; Support. |
| Management | Executive Dashboard; Business Performance; Training Performance; Finance Summary; Trainer Performance; Participant & Alumni Reports. |


# 8. MODEL DATA TINGKAT TINGGI
Model data berikut merupakan daftar entitas awal untuk menyusun ERD. Relasi dan atribut rinci perlu divalidasi pada tahap system analysis.

| Kelompok | Entitas Utama |
|---|---|
| Identity & Access | Users, UserProfiles, Roles, Permissions, UserRoles, AuditLogs |
| Member | Members, MembershipStatuses |
| Training Catalog | TrainingCategories, TrainingPackages, TrainingCurricula, CompetencyUnits |
| Schedule & Class | TrainingSchedules, TrainingClasses, ClassTrainers, ClassLocations |
| Registration | Registrations, Enrollments, Participants, Attendance |
| LMS | Courses, Modules, Lessons, LearningResources, VideoAssets, LearningProgress, Quizzes, Questions, Assignments, Submissions |
| Monitoring | TrainingSessions, TrainingLogbooks, ParticipantProgress, CompetencyAssessments, AssessmentRubrics |
| Certification | CertificateTemplates, Certificates, CertificateSignatories, CertificateVerifications |
| Finance | Invoices, Payments, Discounts, Transactions |
| Content & Partners | Pages, Galleries, News, Articles, Testimonials, Partners, Principals |
| Communication | Notifications, NotificationTemplates, MessageLogs |


## 8.1 Relasi Konseptual Penting
Satu Training Package dapat memiliki banyak Training Schedule atau batch.
Satu Training Class dapat memiliki banyak Enrollment dan satu Enrollment menghubungkan satu peserta dengan satu kelas.
Satu kelas dapat memiliki satu atau lebih trainer melalui tabel penghubung ClassTrainers.
Course, module, lesson, dan resource disusun hierarkis serta dapat dikaitkan dengan kelas atau paket sesuai desain final.
Learning Progress menyimpan aktivitas digital; Training Logbook menyimpan observasi dan catatan trainer.
Satu member dapat memiliki banyak enrollment dan sertifikat.
Satu sertifikat harus merujuk pada peserta, kelas/program, penerbit, dan dasar penerbitannya.

# 9. KEBUTUHAN NON-FUNGSIONAL

| Aspek | Kebutuhan |
|---|---|
| Security | HTTPS, password hashing, RBAC, validasi input, proteksi XSS/CSRF/SQL injection, secure session, audit log. |
| Privacy | Persetujuan pemrosesan data, pembatasan akses, kebijakan retensi, dan pengelolaan data pribadi sesuai ketentuan yang berlaku. |
| Performance | Optimasi query, caching, lazy loading, kompresi gambar, CDN, dan pemisahan penyimpanan video. |
| Scalability | Mendukung pertumbuhan jumlah member, kelas, trainer, materi, dan transaksi. |
| Availability | Target awal 99,5% per bulan, dengan pengecualian pemeliharaan terjadwal dan gangguan pihak ketiga. |
| Backup & Recovery | Backup database terjadwal, pengujian pemulihan, dan kebijakan retensi backup. |
| Usability | Antarmuka konsisten, navigasi jelas, responsif, dan mendukung penggunaan mobile. |
| Accessibility | Kontras memadai, label form jelas, navigasi keyboard dasar, dan teks alternatif gambar. |
| Auditability | Aktivitas penting seperti perubahan nilai, progres, pembayaran, dan sertifikat memiliki jejak perubahan. |


## 9.1 Aturan Penting Data dan Penilaian
Progres menonton video atau membaca materi tidak otomatis berarti peserta menguasai kompetensi.
Kehadiran, penyelesaian LMS, hasil teori, praktik, dan asesmen harus menjadi indikator yang dapat dibedakan.
Nilai dan logbook yang telah disahkan tidak boleh diubah tanpa jejak audit.
Akses materi setelah kelas selesai mengikuti kebijakan retensi dan lisensi konten.
Data NIK atau data sensitif lain dikumpulkan hanya bila diperlukan dan memiliki dasar pemrosesan yang sesuai.

# 10. REKOMENDASI TEKNOLOGI

| Komponen | Opsi Rekomendasi | Catatan |
|---|---|---|
| Frontend | Next.js / React | Responsif, SEO untuk website publik, dan pengalaman portal. |
| Backend | Laravel | Produktif untuk aplikasi bisnis modular dan API. |
| Database | PostgreSQL | Relasional, konsistensi transaksi, dan pelaporan. |
| UI Framework | Tailwind CSS | Konsistensi desain dan pengembangan antarmuka. |
| Authentication | Laravel Sanctum / OAuth | Disesuaikan dengan pola login dan integrasi. |
| API | REST API | Kontrak integrasi frontend dan layanan eksternal. |
| File Storage | S3-compatible Object Storage | Untuk dokumen, gambar, dan aset pembelajaran. |
| Video | Layanan streaming khusus | Mendukung transcoding, adaptive bitrate, dan kontrol akses. |
| Notifications | SMTP / email provider / WhatsApp API | Gunakan kanal resmi dan catat status pengiriman. |
| Deployment | Linux, Nginx, Docker, CI/CD | Pilih sesuai kemampuan tim dan kebutuhan operasi. |

Rekomendasi arsitektur: modular monolith untuk tahap awal. Integrasi Moodle dapat dievaluasi bila kebutuhan LMS berkembang lebih kompleks; keputusan ini perlu mempertimbangkan biaya, pengalaman pengguna, integrasi data, dan kemampuan tim pemelihara.

# 11. ROADMAP PENGEMBANGAN
Gambar 3. Roadmap bertahap. Durasi tiap fase ditentukan setelah estimasi kompleksitas, kapasitas tim, dan anggaran.

| Fase | Fokus | Deliverable |
|---|---|---|
| Phase 1 | Website & fondasi | Landing page, CMS, katalog, jadwal, registrasi, akun member, role dasar. |
| Phase 2 | Training Management | Peserta, alumni dasar, trainer, kelas, attendance, invoice, pembayaran, laporan operasional. |
| Phase 3 | LMS & monitoring | Course, modul, e-book, video, quiz, tugas, learning progress, logbook, progress overview. |
| Phase 4 | Sertifikat & alumni | Template sertifikat, PDF, QR verification, dashboard alumni, histori dan membership. |
| Phase 5 | Integrasi lanjutan | Analitik lanjutan, corporate portal, payment gateway, WhatsApp automation, API, fitur AI terukur. |


## 11.1 Prioritas Minimum Viable Product (MVP)
MVP sebaiknya mencakup website publik, katalog paket, jadwal, pendaftaran, akun member, pengelolaan kelas, trainer dasar, dan CMS. Fitur ini membuat sistem dapat mulai digunakan untuk operasional nyata dan menghasilkan data dasar sebelum LMS penuh dikembangkan.

# 12. KRITERIA PENERIMAAN PRODUK

| ID | Kriteria Uji | Hasil yang Diharapkan |
|---|---|---|
| AC-01 | Admin membuat dan mengubah paket | Data tersimpan dan tampil sesuai status publikasi. |
| AC-02 | Admin membuat jadwal dari paket | Jadwal terhubung ke paket dan kapasitas dapat diatur. |
| AC-03 | Pengunjung mendaftar | Nomor registrasi terbentuk dan data masuk antrean verifikasi. |
| AC-04 | Peserta dikonfirmasi | Akun member dan enrollment kelas dapat dibuat tanpa duplikasi identitas. |
| AC-05 | Trainer membuka kelas | Trainer hanya melihat kelas yang ditugaskan. |
| AC-06 | Peserta mengakses materi | Materi hanya dapat diakses oleh pengguna yang berhak. |
| AC-07 | LMS mencatat progres | Status lesson, quiz, dan tugas tercatat pada akun peserta. |
| AC-08 | Trainer mengisi logbook | Catatan tersimpan per pertemuan dan dapat ditautkan ke peserta. |
| AC-09 | Peserta melihat progres | Dashboard menampilkan indikator dan waktu pembaruan yang jelas. |
| AC-10 | Sertifikat diterbitkan | PDF memiliki nomor unik dan halaman verifikasi dapat memeriksa statusnya. |
| AC-11 | Alumni mendaftar lagi | Alumni menggunakan akun yang sama dan riwayat lama tetap tersedia. |
| AC-12 | Manajemen melihat laporan | Data dapat difilter dan diekspor sesuai hak akses. |


## 12.1 Pengujian yang Wajib Dilakukan
Functional testing untuk setiap fitur dan alur utama.
Role and permission testing untuk memastikan pembatasan akses.
Integration testing untuk registrasi, pembayaran, LMS, dan sertifikat.
Security testing untuk autentikasi, upload file, dan perlindungan data.
User acceptance testing bersama admin, trainer, peserta, dan manajemen.
Backup restoration test sebelum sistem digunakan secara luas.

# 13. RISIKO DAN KEPUTUSAN YANG PERLU DITETAPKAN

| Isu / Risiko | Dampak | Keputusan atau Mitigasi |
|---|---|---|
| Konten video berukuran besar | Biaya storage dan beban server meningkat. | Gunakan layanan video streaming/object storage; tetapkan batas ukuran dan retensi. |
| Aturan kelulusan berbeda | Sertifikat dapat diterbitkan tidak sesuai persyaratan. | Buat completion rule per paket/kelas dan alur validasi. |
| Progres LMS disamakan dengan kompetensi | Gambaran kemampuan peserta menjadi tidak akurat. | Pisahkan learning progress, attendance, assessment, dan trainer observation. |
| Duplikasi akun peserta | Riwayat pelatihan terpecah. | Gunakan pencocokan email/nomor telepon dan proses merge akun yang diaudit. |
| Perubahan jadwal | Peserta dan trainer dapat menerima informasi berbeda. | Sediakan notifikasi, riwayat perubahan, dan konfirmasi. |
| Kewenangan sertifikat | Risiko klaim sertifikasi yang tidak tepat. | Tentukan penerbit, penandatangan, jenis sertifikat, dan proses otorisasi. |
| Data pribadi peserta | Risiko akses atau penggunaan data tidak sah. | Terapkan minimisasi data, kontrol akses, persetujuan, dan kebijakan retensi. |
| Integrasi pihak ketiga | Perubahan API atau biaya layanan. | Gunakan adapter/integration layer dan dokumentasikan ketergantungan. |


## 13.1 Keputusan Bisnis Sebelum Development
Nama domain dan identitas brand digital yang akan digunakan.
Daftar kategori, paket, kurikulum, serta skema sertifikat yang benar-benar ditawarkan.
Kebijakan harga, diskon, pembayaran, pembatalan, dan refund.
Aturan kelulusan setiap program: kehadiran, nilai, praktik, tugas, atau kombinasi.
Apakah materi dapat diakses setelah kelas selesai dan berapa lama.
Jenis sertifikat, pihak penerbit, penandatangan, dan mekanisme verifikasi.
Penyedia email, WhatsApp, payment gateway, object storage, dan video streaming.
Kebijakan data pribadi, retensi, backup, dan penanggung jawab operasional.

# 14. GLOSARIUM

| Istilah | Definisi |
|---|---|
| PRD | Dokumen yang menjelaskan tujuan, ruang lingkup, pengguna, kebutuhan, dan kriteria penerimaan produk. |
| TMS | Training Management System, sistem untuk mengelola administrasi dan pelaksanaan pelatihan. |
| LMS | Learning Management System, sistem untuk mengelola materi, aktivitas, dan evaluasi pembelajaran. |
| Course | Struktur pembelajaran digital untuk suatu program atau kelas. |
| Module / Lesson | Bagian dan unit materi dalam sebuah course. |
| Enrollment | Pencatatan resmi peserta sebagai anggota suatu kelas. |
| Logbook | Catatan kegiatan dan observasi trainer selama pelatihan. |
| Learning Progress | Status penyelesaian aktivitas pembelajaran digital. |
| Competency Assessment | Penilaian pencapaian kompetensi berdasarkan kriteria yang ditetapkan. |
| RBAC | Role-Based Access Control, pembatasan akses berdasarkan peran pengguna. |
| MVP | Minimum Viable Product, versi awal dengan fitur inti yang siap diuji dan digunakan. |


# PENUTUP
PPTI VMES ITMS diharapkan menjadi fondasi transformasi digital yang menyatukan pemasaran, operasional pelatihan, pembelajaran, pemantauan kompetensi, sertifikasi, dan hubungan alumni dalam satu ekosistem.
Dokumen ini merupakan baseline kebutuhan produk. Sebelum implementasi, setiap modul perlu diturunkan menjadi user story, wireframe, ERD rinci, spesifikasi API, dan test case. Perubahan kebutuhan selanjutnya dikelola melalui proses validasi dan persetujuan agar ruang lingkup, biaya, serta jadwal pengembangan tetap terkendali.