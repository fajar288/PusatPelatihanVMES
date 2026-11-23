<?php

/*
| Kebijakan retensi data pribadi (PRD kebutuhan non-fungsional: privasi & retensi). Kebijakan resminya masih menunggu
| keputusan PPTI, jadi SEMUA aturan mati bawaan (null). Isi jumlah hari di .env untuk mengaktifkan; perintah
| `php artisan privacy:prune` berjalan harian dan hanya menjalankan aturan yang diisi (`--dry-run` untuk melihat
| jumlah yang akan diproses). Data keuangan (invoice, bukti bayar) dan sertifikat tidak disentuh aturan ini karena
| wajib disimpan untuk keperluan akuntansi dan verifikasi.
*/
return [
    // Hapus berkas dokumen pendukung pendaftaran yang sudah selesai (terkonfirmasi/ditolak/dibatalkan) setelah N hari.
    'registration_documents_days' => env('PRIVACY_REGISTRATION_DOCUMENTS_DAYS'),

    // Anonimkan pendaftaran yang ditolak/dibatalkan tanpa invoice setelah N hari (nama, kontak, catatan, daftar peserta).
    'closed_registration_days' => env('PRIVACY_CLOSED_REGISTRATION_DAYS'),

    // Hapus entri daftar tunggu setelah N hari sejak jadwalnya selesai.
    'waitlist_days' => env('PRIVACY_WAITLIST_DAYS'),

    // Permintaan penghapusan data dari peserta (portal Privasi): tenggat proses admin sejak diajukan, dan berapa hari
    // sebelum tenggat permintaan ditandai "mendekati tenggat" di admin.
    'deletion_deadline_days' => (int) env('PRIVACY_DELETION_DEADLINE_DAYS', 14),
    'deletion_warning_days' => (int) env('PRIVACY_DELETION_WARNING_DAYS', 3),
];
