<?php

namespace Tests\Unit;

use App\Support\Ai\AiText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiTextTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function cases(): array
    {
        $em = "\u{2014}";
        $en = "\u{2013}";

        return [
            'pemisah em dash berspasi' => ["Kelas dibuka {$em} kuota terbatas.", 'Kelas dibuka - kuota terbatas.'],
            'pemisah em dash menempel' => ["Kelas dibuka{$em}kuota terbatas.", 'Kelas dibuka - kuota terbatas.'],
            'pemisah en dash berspasi' => ["CCNA {$en} batch November", 'CCNA - batch November'],
            'rentang angka' => ["2{$en}6 kalimat", '2-6 kalimat'],
            'rentang jam berspasi' => ["08.00 {$en} 17.00 WIB", '08.00-17.00 WIB'],
            'rentang tanggal' => ["17{$em}21 November", '17-21 November'],
            'rentang hari' => ["Senin{$en}Jumat", 'Senin-Jumat'],
            'butir daftar' => ["Program:\n{$em} CCNA\n  {$en} PLC", "Program:\n- CCNA\n  - PLC"],
            'horizontal bar' => ["A \u{2015} B", 'A - B'],
            'tanpa dash tidak berubah' => ['Rp 7.500.000 - lunas, 08.00-17.00', 'Rp 7.500.000 - lunas, 08.00-17.00'],
        ];
    }

    #[DataProvider('cases')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, AiText::normalize($input));
    }

    public function test_normalize_is_idempotent(): void
    {
        $once = AiText::normalize("Jadwal 17\u{2013}21 Nov \u{2014} Batam");
        $this->assertSame($once, AiText::normalize($once));
        $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}\x{2015}]/u', $once);
    }
}
