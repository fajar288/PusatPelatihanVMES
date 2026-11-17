<?php

namespace Tests\Unit;

use App\Support\Ai\Forecast\Forecaster;
use PHPUnit\Framework\TestCase;

/** Metode prakiraan statistik: nilai titik, backtest, pemilihan metode, baseline musiman, rentang ketidakpastian. */
class ForecasterTest extends TestCase
{
    public function test_point_forecasts_of_each_method(): void
    {
        $h = [4, 8, 6, 10];
        $this->assertSame(10.0, Forecaster::predict('naive', $h));
        $this->assertSame(8.0, Forecaster::predict('moving_average', $h));
        $this->assertNull(Forecaster::predict('seasonal_naive', $h));
        $this->assertNull(Forecaster::predict('moving_average', [1, 2]));

        $year = range(1, 12);                                   // Jan=1 ... Des=12
        $this->assertSame(1.0, Forecaster::predict('seasonal_naive', $year, 1));
        $this->assertSame(3.0, Forecaster::predict('seasonal_naive', $year, 3));
        $this->assertSame(1.0, Forecaster::predict('seasonal_naive', $year, 13));
    }

    public function test_backtest_is_one_step_ahead_without_looking_at_the_future(): void
    {
        $bt = Forecaster::backtest('naive', [10, 12, 14, 16, 18], 2);
        // bulan ke-4 diprakirakan 14 (aktual 16), bulan ke-5 diprakirakan 16 (aktual 18)
        $this->assertSame(['n' => 2, 'mae' => 2.0, 'mape' => 11.8056, 'errors' => [2.0, 2.0]], $bt);
        $this->assertNull(Forecaster::backtest('seasonal_naive', [10, 12, 14, 16, 18], 2));
    }

    public function test_short_history_uses_poisson_interval_and_no_baseline(): void
    {
        $this->assertNull(Forecaster::forecast([3, 4, 5]));
        $f = Forecaster::forecast([5, 5, 5, 5, 5]);
        $this->assertSame('moving_average', $f['method']);                 // seri MAE 0: urutan METHODS
        $this->assertSame(2, $f['holdout']);
        $this->assertSame('poisson', $f['interval']);
        $this->assertSame(['value' => 5.0, 'low' => 2.1, 'high' => 7.9], $f['points'][0]);
        $this->assertNull($f['backtest']['beats_baseline']);
        $this->assertNull($f['backtest']['baseline_mae']);
        $this->assertCount(3, $f['points']);
        // Rentang melebar dengan langkah dan batas bawah tidak negatif.
        $this->assertGreaterThan($f['points'][0]['high'], $f['points'][2]['high']);
        $low = Forecaster::forecast([0, 1, 0, 0, 0]);
        $this->assertSame(0.0, $low['points'][0]['low']);
    }

    public function test_seasonal_pattern_selects_seasonal_method_and_beats_baseline_honestly(): void
    {
        $season = [2, 3, 8, 15, 6, 4, 3, 9, 14, 7, 3, 2];
        $series = [...$season, ...$season, ...array_slice($season, 0, 8)];   // 32 bulan, berakhir Agustus
        $f = Forecaster::forecast($series);
        $this->assertSame('seasonal_naive', $f['method']);
        $this->assertSame('backtest', $f['interval']);
        $this->assertSame([14.0, 7.0, 3.0], array_column($f['points'], 'value'));   // Sep, Okt, Nov tahun lalu
        $this->assertSame(0.0, $f['backtest']['mae']);
        $this->assertTrue($f['backtest']['beats_baseline']);
        $this->assertSame(['value' => 14.0, 'low' => 14.0, 'high' => 14.0], $f['points'][0]);

        // Deret naik stabil: rata-rata/naive menang, baseline musiman kalah dan dilaporkan.
        $trend = range(10, 41);
        $t = Forecaster::forecast($trend);
        $this->assertSame('naive', $t['method']);
        $this->assertTrue($t['backtest']['beats_baseline']);
        $this->assertSame(12.0, $t['backtest']['baseline_mae']);
        $this->assertSame(41.0, $t['points'][0]['value']);
    }
}
