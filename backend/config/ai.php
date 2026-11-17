<?php

/*
 * Tata kelola AI terukur. Aturan kerja: docs/PROMPT-AI-Terukur.md.
 *
 * Semua fitur MATI bawaan. Sebuah use case hanya berjalan bila:
 *   1. flag global AI_ENABLED=true,
 *   2. flag use case-nya true (mis. AI_EARLY_WARNING_ENABLED),
 *   3. untuk use case berbasis Claude (method "llm"): ANTHROPIC_API_KEY terisi,
 *   4. tidak sedang dijeda admin (menu AI > Status fitur), dan
 *   5. batas harian panggilan/token belum tercapai.
 * Bila salah satu tidak terpenuhi, fitur kembali ke aturan atau proses manual (AiGovernance::status()).
 *
 * Chatbot informasi publik punya konfigurasi sendiri (config/chatbot.php, CHATBOT_ENABLED) dan tidak ikut flag global.
 * Ia hanya didaftarkan di sini supaya pemakaian token dan biayanya tampil di halaman admin yang sama.
 */
$llm = fn (string $prefix, string $promptVersion, int $calls, int $tokens, string $effort = 'medium', int $maxTokens = 4000) => [
    'model' => env("AI_{$prefix}_MODEL", 'claude-opus-5-5'),
    'effort' => env("AI_{$prefix}_EFFORT", $effort),
    'max_tokens' => (int) env("AI_{$prefix}_MAX_TOKENS", $maxTokens),
    'prompt_version' => $promptVersion,
    'daily_calls' => (int) env("AI_{$prefix}_DAILY_CALLS", $calls),
    'daily_tokens' => (int) env("AI_{$prefix}_DAILY_TOKENS", $tokens),
];

return [
    'enabled' => (bool) env('AI_ENABLED', false),

    'api_key' => env('ANTHROPIC_API_KEY'),

    // Batas waktu panggilan sinkron (detik). Di cPanel tidak ada worker permanen, jadi panggilan di request web harus singkat.
    'timeout' => (float) env('AI_TIMEOUT', 30),

    // Insight yang belum ditinjau kedaluwarsa setelah N hari (perintah ai:expire-insights).
    'insight_ttl_days' => (int) env('AI_INSIGHT_TTL_DAYS', 30),

    // Laporan ai:evaluate disimpan di storage/app/<folder>.
    'evaluation_dir' => 'ai-evaluations',

    // Harga per 1 juta token (USD) untuk perkiraan biaya. Sesuaikan bila harga resmi berubah.
    'pricing' => [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10, 'cache_write' => 2.50],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125],
    ],

    /*
     * Daftar use case. method: "statistical" (tanpa LLM, deterministik) atau "llm" (Claude, butuh API key).
     * review: "required" = wajib disetujui admin sebelum tampil ke pengguna; "staff" = hanya tampil ke staf/trainer/
     * perusahaan sponsor; "logged" = boleh langsung tampil, tetap dicatat.
     * readiness: ambang minimum data riil sebelum hasil evaluasi boleh dipercaya (lihat AiReadiness).
     */
    'use_cases' => [
        'early_warning' => [
            'label' => 'Peringatan dini peserta berisiko',
            'description' => 'Skor risiko 0-100 dan alasan utama per peserta aktif dari kehadiran, progres materi, nilai, dan logbook.',
            'method' => 'statistical',
            'enabled' => (bool) env('AI_EARLY_WARNING_ENABLED', false),
            'review' => 'staff',
            'metrics' => ['Precision', 'Recall', 'F1'],
            'baseline' => 'Aturan peserta berisiko di Dashboard Eksekutif',
            'readiness' => ['finished_enrollments' => 200, 'failed_enrollments' => 20, 'finished_classes' => 10],
            // Skor risiko (0-100) mulai dari angka ini dianggap "berisiko" dan memicu notifikasi ke trainer & perusahaan sponsor.
            'threshold' => (int) env('AI_EARLY_WARNING_THRESHOLD', 50),
            // Insight harian berlaku 2 hari; bila perintah harian berhenti, skor lama tidak tampil terus.
            'ttl_hours' => 48,
        ],
        'recommendation' => [
            'label' => 'Rekomendasi training',
            'description' => 'Usulan program dari riwayat pelatihan, prasyarat, gap kompetensi, dan pola peserta lain.',
            'method' => 'statistical',
            'enabled' => (bool) env('AI_RECOMMENDATION_ENABLED', false),
            'review' => 'logged',
            'metrics' => ['CTR', 'Konversi', 'Repeat enrollment'],
            'baseline' => 'Program terpopuler di kategori yang sama',
            'readiness' => ['members_with_history' => 100, 'repeat_members' => 30],
        ],
        'feedback_analysis' => [
            'label' => 'Analisis umpan balik survei',
            'description' => 'Tema, sentimen, dan kategori komentar survei evaluasi pelatihan.',
            'method' => 'llm',
            'enabled' => (bool) env('AI_FEEDBACK_ENABLED', false),
            'review' => 'required',
            'metrics' => ['F1', 'Akurasi', 'Actionability'],
            'baseline' => 'Label manual admin',
            'readiness' => ['survey_comments' => 200],
        ] + $llm('FEEDBACK', '2026-11-17.1', 50, 2_000_000, 'low'),
        'lms_assistant' => [
            'label' => 'Asisten Belajar',
            'description' => 'Menjawab pertanyaan peserta hanya dari materi lesson yang disetujui, dengan sumber.',
            'method' => 'llm',
            // Flag AI_CHATBOT_ENABLED dipertahankan dari kerangka Asisten Belajar (tahap sebelumnya), terpisah dari chatbot publik.
            'enabled' => (bool) env('AI_CHATBOT_ENABLED', false),
            'review' => 'logged',
            'metrics' => ['Kualitas jawaban', 'Cakupan sumber', 'Unresolved rate'],
            'baseline' => 'Pertanyaan ke trainer lewat forum diskusi',
            'readiness' => ['published_lessons' => 30],
            // Batas pertanyaan per peserta per hari (di luar batas harian use case).
            'per_user_daily' => (int) env('AI_LMS_ASSISTANT_PER_USER_DAILY', 20),
            // Panjang maksimum materi (karakter teks polos) yang dikirim per pertanyaan; course yang lebih panjang
            // dipotong per lesson dengan prioritas lesson yang sedang dibuka, dan jumlah lesson yang dipakai dicatat.
            'max_material_chars' => (int) env('AI_LMS_ASSISTANT_MAX_MATERIAL_CHARS', 200_000),
            'question_max' => 1000,
        ] + $llm('LMS_ASSISTANT', '2026-11-17.2', 300, 3_000_000, 'low'),
        'corporate_insight' => [
            'label' => 'Insight perusahaan',
            'description' => 'Ringkasan gap kompetensi dan usulan tindakan per organisasi dari angka agregat.',
            'method' => 'llm',
            'enabled' => (bool) env('AI_CORPORATE_INSIGHT_ENABLED', false),
            'review' => 'required',
            'metrics' => ['Acceptance rate', 'Konversi menjadi permintaan training'],
            'baseline' => 'Laporan gap kompetensi tanpa narasi',
            'readiness' => ['organizations_with_targets' => 3, 'competency_assessments' => 50],
        ] + $llm('CORPORATE_INSIGHT', '2026-11-17.1', 30, 1_000_000),
        'demand_forecast' => [
            'label' => 'Prakiraan permintaan',
            'description' => 'Prakiraan jumlah pendaftar per program, kategori, dan bulan beserta rentang ketidakpastian.',
            'method' => 'statistical',
            'enabled' => (bool) env('AI_DEMAND_FORECAST_ENABLED', false),
            'review' => 'staff',
            'metrics' => ['MAE', 'MAPE'],
            'baseline' => 'Seasonal naive (bulan yang sama tahun lalu)',
            'readiness' => ['registration_months' => 24],
        ],
        'public_chatbot' => [
            'label' => 'Chatbot informasi publik',
            'description' => 'Menjawab pertanyaan pengunjung website dari data katalog. Diatur terpisah lewat CHATBOT_ENABLED.',
            'method' => 'llm',
            'independent' => true,
            'enabled' => (bool) env('CHATBOT_ENABLED', false),
            'review' => 'logged',
            'metrics' => ['Terjawab', 'Tidak terjawab', 'Umpan balik pengunjung'],
            'baseline' => 'Pertanyaan lewat WhatsApp admin',
            'readiness' => [],
            // model, effort, versi prompt, dan batas harian dibaca dari config/chatbot.php (AiUseCases::get()).
            'config' => 'chatbot',
        ],
    ],
];
