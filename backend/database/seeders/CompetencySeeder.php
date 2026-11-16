<?php

namespace Database\Seeders;

use App\Models\Competency;
use App\Models\TrainingPackage;
use Illuminate\Database\Seeder;

/**
 * Master kompetensi awal (Roadmap 5F) + tautan ke program katalog yang membangunnya. Idempoten; level adalah usulan awal
 * (capaian lulusan 0-100) yang bisa diubah admin di menu "Master kompetensi". Program yang tidak ada dilewati.
 */
class CompetencySeeder extends Seeder
{
    /** kode => [nama, kategori, [slug paket => level]] */
    private const DATA = [
        'NET' => ['Jaringan komputer (routing & switching)', 'Infrastruktur TI', ['cisco-ccna' => 70, 'mikrotik-mtcna' => 65, 'cisco-ccnp-enterprise' => 90]],
        'CLOUD' => ['Komputasi awan', 'Infrastruktur TI', ['aws-cloud-practitioner' => 65, 'devops-engineering' => 80]],
        'DEVOPS' => ['DevOps & otomasi deployment', 'Pengembangan perangkat lunak', ['devops-engineering' => 75]],
        'SEC' => ['Keamanan siber', 'Keamanan informasi', ['cyber-security-foundation' => 70]],
        'LINUX' => ['Administrasi sistem Linux', 'Infrastruktur TI', ['linux-administration' => 75, 'devops-engineering' => 60]],
        'PY' => ['Pemrograman Python', 'Pengembangan perangkat lunak', ['python-programming' => 70]],
    ];

    public function run(): void
    {
        $order = 0;
        foreach (self::DATA as $code => [$name, $category, $packages]) {
            $c = Competency::query()->updateOrCreate(['code' => $code], ['name' => $name, 'category' => $category, 'sort_order' => ++$order]);
            $ids = TrainingPackage::query()->whereIn('slug', array_keys($packages))->pluck('id', 'slug');
            $c->packages()->syncWithoutDetaching(collect($packages)->filter(fn ($lv, $slug) => isset($ids[$slug]))
                ->mapWithKeys(fn ($lv, $slug) => [$ids[$slug] => ['level' => $lv]])->all());
        }
    }
}
