<?php

namespace Tests\Unit;

use App\Support\SiteIdentity;
use App\Support\ThemeIcons;
use PHPUnit\Framework\TestCase;

/** Pilihan ikon di admin harus persis ikon sprite yang ada di frontend (tanpa ikon media sosial). */
class ThemeIconsTest extends TestCase
{
    public function test_icon_options_match_frontend_sprite(): void
    {
        $file = dirname(__DIR__, 3).'/frontend/src/components/ui/icons.ts';
        if (! is_file($file)) {
            $this->markTestSkipped('frontend tidak tersedia');
        }
        preg_match('/export const ICON_NAMES = \[(.*?)\] as const;/s', (string) file_get_contents($file), $m);
        preg_match_all('/"([a-z]+)"/', $m[1] ?? '', $names);
        $frontend = $names[1];
        $this->assertNotEmpty($frontend);

        $social = array_keys(SiteIdentity::SOCIAL_ICONS);
        $this->assertSame(array_values(array_diff($frontend, $social)), ThemeIcons::names());
        $this->assertSame([], array_diff($social, $frontend));
        foreach (ThemeIcons::options() as $name => $label) {
            $this->assertStringContainsString("($name)", $label);
        }
    }
}
