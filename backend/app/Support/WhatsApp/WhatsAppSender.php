<?php

namespace App\Support\WhatsApp;

/**
 * Pengirim WhatsApp (PRD Roadmap 5 §6). Driver: `log` (LogWhatsAppSender, bawaan) dan `fonnte` (FonnteWhatsAppSender),
 * dipilih lewat WHATSAPP_DRIVER. Penyedia lain cukup mengimplementasikan antarmuka ini dan melapor status lewat
 * NotificationDispatcher::receipt($provider, $messageId, 'delivered'|'read'|'failed') dari controller webhook-nya sendiri.
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
     * @throws PermanentWhatsAppFailure bila gagal permanen (tidak di-retry, mis. nomor/token tidak valid)
     * @throws \RuntimeException bila gagal sementara (akan di-retry sesuai kebijakan)
     */
    public function send(string $to, string $text): string;
}
