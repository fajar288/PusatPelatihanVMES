<?php

namespace Tests\Unit;

use App\Support\Ai\DataReference;
use App\Support\Ai\Metrics;
use App\Support\Ai\PersonalData;
use App\Support\Ai\PersonalDataLeak;
use Tests\TestCase;

/** Helper AI: penyaring data pribadi, pseudonim, referensi data (hash), dan metrik evaluasi. */
class AiSupportTest extends TestCase
{
    public function test_scrub_removes_email_phone_and_identity_numbers(): void
    {
        $cases = [
            'Kirim ke budi.santoso+kelas@pt-abc.co.id ya' => 'Kirim ke [email] ya',
            'WA saya 0812-3456-7890' => 'WA saya [telepon]',
            'hubungi +62 812 3456 7890 atau 6281234567890' => 'hubungi [telepon] atau [telepon]',
            'telp 08123456789.' => 'telp [telepon].',
            'NIK 3171012345678901' => 'NIK [nomor-identitas]',
            'Kartu 4111 1111 1111 1111' => 'Kartu [nomor-identitas]',
            // Angka biasa tidak tersentuh.
            'Nilai 85, kehadiran 80%, kelas 2026-11-20, kuota 25, kode REG-202611-0004' => 'Nilai 85, kehadiran 80%, kelas 2026-11-20, kuota 25, kode REG-202611-0004',
            'Harga Rp 12.500.000 untuk 3 hari' => 'Harga Rp 12.500.000 untuk 3 hari',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, PersonalData::scrub($in), $in);
            $this->assertFalse(PersonalData::containsPersonalData(PersonalData::scrub($in)), $in);
        }
    }

    public function test_assert_clean_checks_nested_payloads(): void
    {
        PersonalData::assertClean(['a' => ['b' => 'aman', 'c' => 42]]);
        $this->expectException(PersonalDataLeak::class);
        PersonalData::assertClean(['a' => ['b' => 'email: x@y.id']]);
    }

    public function test_pseudonym_is_stable_and_distinct(): void
    {
        $a = PersonalData::pseudonym('member', 1);
        $this->assertMatchesRegularExpression('/^P-[0-9A-F]{8}$/', $a);
        $this->assertSame($a, PersonalData::pseudonym('member', 1));
        $this->assertNotSame($a, PersonalData::pseudonym('member', 2));
        $this->assertNotSame($a, PersonalData::pseudonym('trainer', 1));
    }

    public function test_data_reference_keeps_ids_and_hashes_only(): void
    {
        $ref = (new DataReference)->add('enrollment', ['name' => 'Budi', 'score' => 70], 5)->toArray();
        $this->assertSame('enrollment', $ref['items'][0]['type']);
        $this->assertSame(5, $ref['items'][0]['id']);
        $this->assertStringNotContainsString('Budi', (string) json_encode($ref));
        $this->assertSame(DataReference::hash(['score' => 70, 'name' => 'Budi']), $ref['items'][0]['hash']);
        $this->assertNotSame(DataReference::hash(['score' => 71, 'name' => 'Budi']), $ref['items'][0]['hash']);
    }

    public function test_metrics(): void
    {
        $m = Metrics::binary([true, true, false, false, true], [true, false, true, false, true]);
        $this->assertSame([2, 1, 1, 1], [$m['tp'], $m['fp'], $m['fn'], $m['tn']]);
        $this->assertSame(0.6667, $m['precision']);
        $this->assertSame(0.6667, $m['recall']);
        $this->assertSame(0.6667, $m['f1']);
        $this->assertSame(0.6, $m['accuracy']);
        $this->assertNull(Metrics::binary([false], [false])['precision']);

        $mc = Metrics::multiclass(['a', 'b', 'a'], ['a', 'b', 'b']);
        $this->assertSame(0.6667, $mc['accuracy']);
        $this->assertSame(0.6667, $mc['macro_f1']);

        $f = Metrics::forecast([10, 12, 0], [8, 12, 0]);
        $this->assertSame(0.6667, $f['mae']);
        $this->assertSame(12.5, $f['mape']);
        $this->assertNull(Metrics::rate(1, 0));
    }
}
