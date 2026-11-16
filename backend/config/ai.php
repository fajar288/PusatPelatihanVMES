<?php

/*
 * Tata kelola AI (PRD Roadmap 5 §9, §9.1). Tahap 5G hanya menyiapkan kerangka: Asisten Belajar portal member
 * terlihat sebagai "Belum tersedia" dan endpoint-nya selalu 503 `ai_unavailable`. Tidak ada panggilan API AI
 * dan tidak ada data peserta yang dikirim ke mana pun. Asisten sungguhan menyusul di tahap 5I.5
 * (`docs/PROMPT-AI-Terukur.md`), yang juga menambahkan flag global & per use case di berkas ini.
 *
 * Chatbot informasi publik punya konfigurasi sendiri (`config/chatbot.php`, CHATBOT_ENABLED) dan tidak terpengaruh.
 */
return [
    'assistant' => [
        // Flag terpisah dari chatbot publik. Walau true, kerangka 5G tetap mengembalikan 503 (belum ada implementasi).
        'enabled' => (bool) env('AI_CHATBOT_ENABLED', false),
    ],
];
