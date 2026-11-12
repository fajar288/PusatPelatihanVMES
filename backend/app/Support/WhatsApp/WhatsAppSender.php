<?php

namespace App\Support\WhatsApp;

/**
 * Pengirim WhatsApp (PRD Roadmap 5 §6). Penyedia WA DITUNDA: hanya driver `log` (LogWhatsAppSender).
 * Penyedia nyata nanti cukup mengimplementasikan antarmuka ini dan melapor status lewat
 * NotificationDispatcher::receipt($provider, $messageId, 'delivered'|'read'|'failed').
 */
interface WhatsAppSender
{
    /** Kanal aktif (WHATSAPP_ENABLED=true). */
    public function enabled(): bool;

    /** Nama penyedia untuk dicatat di pengiriman, mis. "log". */
    public function provider(): string;

    /**
     * Kirim teks ke nomor internasional tanpa "+" (mis. 6281234567890).
     *
     * @return string ID pesan dari penyedia
     *
     * @throws \RuntimeException bila gagal (akan di-retry sesuai kebijakan)
     */
    public function send(string $to, string $text): string;
}
