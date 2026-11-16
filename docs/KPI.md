# Definisi KPI — PRD Roadmap 5 §8.1

Dokumen ini adalah **sumber kebenaran** rumus KPI resmi. Implementasinya ada di `backend/app/Services/KpiService.php`
dan diuji di `backend/tests/Feature/AdvancedAnalyticsTest.php` dengan angka yang dihitung manual. Mengubah rumus berarti
mengubah dokumen ini, `KpiService::DEFINITIONS`, dan tesnya sekaligus.

## Lingkup perhitungan

Setiap KPI dihitung untuk satu **lingkup**:

| Parameter | Arti |
|---|---|
| Periode `from`–`to` | Tanggal inklusif. Default Dashboard Eksekutif & Corporate Portal: 12 bulan terakhir (awal bulan s.d. hari ini). |
| Tanggal acuan | `min(to, hari ini)`, dipakai untuk "sesi yang sudah berlangsung". |
| Segmen | `all` (semua), `self` (enrollment `type = self`, self-funded), `corporate` (enrollment `type = corporate`, disponsori organisasi). |
| Filter program | Dari Dashboard Eksekutif (kategori, paket, kelas, trainer, status kelas): membatasi enrollment ke kelas yang cocok; request/invoice yang belum punya kelas dicocokkan lewat paket. Tidak berlaku untuk Repeat Order Rate dan tabel cohort. |
| Pengguna perusahaan | Di Corporate Portal & API enterprise: hanya enrollment yang **disponsori organisasi itu** (+ batas unit pengguna), request organisasi (batas unit), dan invoice organisasi. Data mandiri karyawan atau sponsor lain tidak pernah masuk (§20.11). Agregat tetap menghitung peserta yang sudah keluar dari perusahaan. |

## Istilah bersama

- **Dropped**: peserta mengundurkan diri / dibatalkan. Tidak dihitung di Completion dan Attendance (dilaporkan terpisah).
- **Order**: satu invoice yang **tidak dibatalkan**, sudah **lunas** (`paid_at` terisi), dan **nilai bersihnya > 0**
  (`amount_paid > amount_refunded`; invoice yang direfund penuh bukan order).
  Order **corporate** = invoice milik organisasi (`organization_id` terisi: Corporate Training Request atau pendaftaran
  kolektif yang ditautkan ke organisasi). Order **mandiri** = sisanya.
- **Nilai diakui satu order**: jumlah transaksi pembayaran berstatus `paid` (nominal diterima finance, atau nominal yang
  dinyatakan bila belum diisi) **dikurangi** refund berstatus `completed`.

## KPI

### `completion_rate` — Completion Rate

> Completed enrollments / enrollments eligible for completion

- **Penyebut (eligible)**: enrollment yang
  1. statusnya bukan `dropped`,
  2. kelasnya tidak `cancelled`,
  3. tanggal **selesai kelas** berada dalam periode, dan
  4. kelasnya **sudah berakhir** (`end_date` sebelum hari ini atau status kelas `completed`) **atau** hasil akhir enrollment
     sudah ditetapkan (`completed`/`failed`).
- **Pembilang**: enrollment eligible berstatus `completed`.
- Enrollment eligible yang masih `enrolled`/`attending` (kelas sudah selesai tetapi kelulusan belum ditetapkan) **tetap
  di penyebut** sebagai belum lulus, dan dilaporkan sebagai `pending_evaluation` agar koordinator menuntaskannya.
- Hasil: persen 1 desimal; `null` bila penyebut 0.

### `attendance_rate` — Attendance Rate

> Attended learning units / scheduled learning units

- **Learning unit** = satu sesi kelas (`class_sessions`).
- **Penyebut**: untuk setiap enrollment bukan `dropped` di kelas yang tidak dibatalkan, jumlah sesi kelasnya yang bertanggal
  dalam periode **dan** sudah berlangsung (sampai tanggal acuan). Dijumlahkan untuk semua enrollment (pooled, bukan rata-rata persen).
- **Pembilang**: catatan kehadiran berstatus `present` atau `late` pada sesi-sesi tersebut. `excused` (izin) dan `absent`
  dihitung **tidak hadir**.
- Hasil: persen 1 desimal; `null` bila penyebut 0.
- Catatan: kartu "Rata-rata kehadiran" di Dashboard Eksekutif tetap rata-rata persen per peserta (indikator lama, bukan KPI ini).

### `repeat_order_rate` — Repeat Order Rate

> Corporate accounts with repeat purchase / corporate accounts eligible

- **Penyebut**: organisasi yang memiliki **≥ 1 order** dengan `paid_at` sampai akhir periode.
- **Pembilang**: organisasi di penyebut yang memiliki **≥ 2 order** dengan `paid_at` sampai akhir periode.
- Hanya berlaku untuk akun corporate; segmen `self` ditampilkan `n/a`.
- Hasil: persen 1 desimal; `null` bila penyebut 0.

### `average_order_value` — Average Order Value (AOV)

> Recognized transaction value / number of orders

- **Order dihitung**: order dengan `paid_at` dalam periode (sesuai segmen).
- **Pembilang**: jumlah nilai diakui order-order tersebut (pembayaran terverifikasi dikurangi refund selesai, seluruh umur order).
- **Penyebut**: jumlah order tersebut.
- Hasil: rupiah 2 desimal; `null` bila tidak ada order.

### `training_conversion` — Training Conversion

> Paid enrollments / qualified opportunities

- **Peluang terkualifikasi** (dalam satuan peserta/kursi):
  - pendaftaran yang **dibuat** dalam periode dan statusnya **bukan `rejected`**, dihitung `participants_count`
    (segmen `self` = pendaftaran individu tanpa organisasi; `corporate` = pendaftaran kolektif atau yang ditautkan organisasi);
  - Corporate Training Request yang **dikirim** (`submitted_at`) dalam periode, dihitung jumlah kursinya (`seats`). Draf tidak
    dihitung; request yang kemudian dibatalkan tetap peluang (hilang).
- **Enrollment lunas**: enrollment (status apa pun) yang berasal dari peluang di atas **dan** sumbernya lunas:
  - pendaftaran dengan invoice tidak batal yang `paid_at` terisi, atau pendaftaran terkonfirmasi dengan total 0 (gratis);
  - kursi request yang request-nya sudah lunas (`paid_at`).
- Hasil: persen 1 desimal; `null` bila tidak ada peluang.

### `at_risk_rate` — At-Risk Rate

> Active participants flagged by defined risk rules / active participants

- **Peserta aktif**: enrollment `enrolled`/`attending` di kelas yang beririsan dengan periode, sudah mulai (`start_date`
  paling lambat hari ini), dan tidak `draft`/`cancelled`.
- **Aturan risiko** (sama dengan tabel "Peserta berisiko" Dashboard Eksekutif, `AnalyticsService`), ditandai bila ≥ 1 terpenuhi:
  1. kehadiran (hadir/terlambat ÷ sesi yang sudah lewat) di bawah `min_attendance_pct` aturan kelulusan paket (fallback 75%);
  2. progres LMS di bawah 60% dari porsi waktu kelas yang sudah berjalan;
  3. rata-rata kuis di bawah 60;
  4. tidak membuka LMS lebih dari 7 hari (atau belum pernah) selama kelas masih berjalan.

  Aturan 2–4 hanya untuk kelas yang punya materi LMS.
- Hasil: persen 1 desimal; `null` bila tidak ada peserta aktif. Perusahaan hanya melihat **nama** peserta berisiko bila
  perannya `view_progress` dan detail progres peserta itu boleh dibuka (kebijakan `exit_access`).

## Indikator turunan (bukan KPI §8.1)

| Indikator | Definisi | Dipakai di |
|---|---|---|
| Training Utilization | Kursi request yang dikirim dalam periode & sudah lunas (dibeli) dibanding kursi yang enrollment-nya **dipakai** (lulus atau ≥ 1 kehadiran hadir/terlambat). | Corporate Portal §20.15 |
| Training Hours | Jumlah durasi sesi yang dihadiri (hadir/terlambat) sampai akhir periode. Durasi sesi = jam mulai–selesai sesi, lalu jam kelas, lalu `duration_hours` paket ÷ jumlah sesi, lalu 0. Per karyawan = total ÷ member unik. | Corporate Portal §20.15 |
| Cost per Employee | Nilai diakui order organisasi yang lunas dalam periode ÷ member unik dengan enrollment bersponsor di kelas yang beririsan periode. Hanya peran `view_finance`. Per departemen mengikuti unit request. | Corporate Portal §20.15 |
| Program Effectiveness | Per program: peserta, lulus ÷ (lulus + tidak lulus), rata-rata nilai akhir, % kompeten & rata-rata skor penilaian tervalidasi. | Corporate Portal §20.15 |
| Funnel corporate | Satuan Corporate Training Request yang **dibuat** dalam periode: lead (dibuat, termasuk draf), dikirim, penawaran, disetujui, lunas, selesai; repeat = request lunas dari organisasi yang sebelumnya sudah punya request lunas. | Dashboard Eksekutif |
| Cohort & repeat | Pelanggan dikelompokkan menurut bulan order pertama (dalam periode): jumlah pelanggan, yang punya ≥ 2 order sampai akhir periode, rata-rata hari ke order kedua, AOV order cohort. Pelanggan corporate = organisasi; mandiri = member (atau email penagihan). | Dashboard Eksekutif |
| Competency gap | Required = target per posisi organisasi; actual = skor kriteria rubrik tertaut pada penilaian tervalidasi terbaru, atau bila tidak ada, level program yang diluluskan; hanya dari enrollment yang disponsori organisasi itu. Gap = required − actual (tanpa bukti: gap = required). Lihat `CompetencyGapService`. | Corporate Portal §20.16, admin |

## Tempat KPI ditampilkan

- **Dashboard Eksekutif** (`/admin/dashboard-eksekutif`): tabel "KPI Roadmap 5 §8.1" per segmen, kartu corporate vs mandiri,
  funnel, cohort. Completion Rate di kartu pembelajaran memakai definisi ini. Ekspor `executive` memuat baris KPI dan funnel.
- **Corporate Portal** (`/corporate/[org]/analitik`): KPI segmen corporate dengan scope pengguna perusahaan.
- **API enterprise** `GET /api/public/v1/analytics` (scope `analytics.read`): blok `kpi_8_1` + `period`.
- Ringkasan Corporate Portal dan `kpi.completion_rate` / `kpi.attendance_avg` API enterprise: Completion & Attendance Rate
  sepanjang waktu, dibulatkan.
