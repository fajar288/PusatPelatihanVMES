<?php

/*
 * Notification Engine (PRD Roadmap 5 §6 / tahap 5D).
 * Satu business event dicatat sekali di `notification_events`, lalu engine menentukan penerima per scope
 * (peserta, admin perusahaan, trainer, admin VMES), kanal, template berversi, jadwal, retry, dan status pengiriman.
 */
return [
    // true: pengiriman "segera" diproses di request yang memicu (setelah commit). false: hanya oleh `notifications:dispatch`.
    'inline' => (bool) env('NOTIFICATIONS_INLINE', true),

    // Kebijakan retry per pengiriman (delivery). Jeda dalam menit sebelum percobaan ke-2, ke-3, dst.
    'retry' => [
        'max_attempts' => (int) env('NOTIFICATIONS_MAX_ATTEMPTS', 4),
        'backoff_minutes' => [1, 5, 30, 120],
        // pengiriman yang "terkunci" lebih lama dari ini dianggap macet dan boleh diambil ulang
        'stale_lock_minutes' => 10,
    ],

    // WhatsApp: penyedia DITUNDA (keputusan atasan). Hanya driver `log` (pesan ditulis ke log aplikasi). Mati bawaan.
    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
        'driver' => env('WHATSAPP_DRIVER', 'log'),
        'log_channel' => env('WHATSAPP_LOG_CHANNEL'),
        'country_code' => '62',
    ],

    // Peringatan progres berisiko (PRD §6 "Progress Risk"): satu peringatan per enrollment per N hari.
    'risk' => [
        'enabled' => (bool) env('NOTIFICATIONS_RISK_ENABLED', true),
        'repeat_days' => 7,
    ],
];
