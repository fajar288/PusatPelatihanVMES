<?php

namespace App\Support\Ai\EarlyWarning;

use App\Services\AnalyticsService;

/**
 * Baseline pembanding: aturan peserta berisiko Dashboard Eksekutif (AnalyticsService::atRisk) yang diterapkan pada
 * nilai mentah yang sama. Peserta ditandai bila kehadiran di bawah syarat lulus, progres materi di bawah 60% dari
 * target jadwal, rata-rata kuis di bawah 60, atau (khusus kelas aktif) tidak membuka materi lebih dari 7 hari.
 */
final class BaselineRules
{
    /** @param  array<string, mixed>  $r  nilai mentah dari FeatureExtractor */
    public static function flagged(array $r, bool $finished): bool
    {
        if ($r['attendance'] !== null && $r['attendance'] < $r['min_attendance']) {
            return true;
        }
        if ($r['lms_percent'] !== null) {
            if ($r['lms_percent'] < (int) round($r['lms_expected'] * 0.6)) {
                return true;
            }
            if ($r['quiz_avg'] !== null && $r['quiz_avg'] < AnalyticsService::RISK_MIN_QUIZ) {
                return true;
            }
            if (! $finished && ($r['never_opened'] || $r['inactive_days'] > AnalyticsService::RISK_INACTIVE_DAYS)) {
                return true;
            }
        }

        return false;
    }
}
