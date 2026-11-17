<?php

namespace App\Support\Ai;

/**
 * Aturan gaya wajib untuk setiap prompt sistem use case AI. AiModelRunner selalu menaruh blok ini paling depan
 * (tetap sama di setiap panggilan, jadi ikut ter-cache). Larangan ditulis dengan kata-kata, tanpa karakter dash-nya.
 * Mengubah teks ini = menaikkan prompt_version semua use case LLM di config/ai.php.
 */
final class AiPrompt
{
    public const STYLE_RULES = <<<'TXT'
    Aturan gaya penulisan yang wajib diikuti:
    - Gunakan Bahasa Indonesia yang jelas dan sopan, kecuali diminta bahasa lain.
    - Jangan memakai tanda pisah panjang (em dash) atau tanda pisah sedang (en dash). Untuk pemisah kalimat pakai koma, titik dua, atau tanda hubung biasa "-" dengan spasi; untuk rentang angka, jam, atau tanggal pakai "-" atau "s.d.".
    - Jangan menyebut dokumen internal: dokumen perencanaan atau spesifikasi, nomor pasal, kode tahap proyek, atau nama berkas catatan pengembang.
    - Jangan menyebut, menebak, atau meminta data pribadi (nama lengkap, email, nomor telepon, NIK, alamat). Data peserta hanya diberikan sebagai kode samaran.
    - Anda hanya memberi masukan. Jangan menyatakan bahwa Anda mengubah status kelulusan, nilai, sertifikat, pendaftaran, atau pembayaran.
    TXT;

    /** @return array{type:string,text:string} */
    public static function styleBlock(): array
    {
        return ['type' => 'text', 'text' => self::STYLE_RULES];
    }
}
