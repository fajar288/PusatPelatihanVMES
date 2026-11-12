<?php

/*
 * Chatbot informasi publik (Phase 5 — Measurable AI, PRD Roadmap 5 §9). Penyedia: Claude (Anthropic API).
 * NONAKTIF bawaan: aktif hanya bila CHATBOT_ENABLED=true DAN ANTHROPIC_API_KEY terisi.
 */
return [
    'enabled' => (bool) env('CHATBOT_ENABLED', false),

    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('CHATBOT_MODEL', 'claude-opus-5-5'),

    // Tingkat effort Claude: chat informasi cukup "low" (lebih cepat & hemat).
    'effort' => env('CHATBOT_EFFORT', 'low'),

    'max_tokens' => (int) env('CHATBOT_MAX_TOKENS', 8000),

    // Versi prompt sistem — dicatat di setiap log (governance: model & prompt/version harus tercatat).
    'prompt_version' => '2026-11-12.1',

    // Batas pemakaian (biaya API dihitung per token).
    'per_ip_per_minute' => (int) env('CHATBOT_PER_IP_PER_MINUTE', 6),
    'per_ip_per_day' => (int) env('CHATBOT_PER_IP_PER_DAY', 40),
    'daily_limit' => (int) env('CHATBOT_DAILY_LIMIT', 500),

    // Riwayat percakapan yang dikirim klien: maks. giliran & panjang pesan.
    'max_turns' => 12,
    'max_chars' => 800,

    // Log percakapan dihapus otomatis setelah N hari (model:prune).
    'log_retention_days' => (int) env('CHATBOT_LOG_RETENTION_DAYS', 90),
];
