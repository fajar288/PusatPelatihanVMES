<?php

namespace Tests\Feature;

use App\Filament\Resources\AssessmentRubrics\Pages\CreateAssessmentRubric;
use App\Filament\Resources\AssessmentRubrics\Pages\EditAssessmentRubric;
use App\Filament\Support\Layout;
use App\Models\AssessmentRubric;
use App\Models\TrainingPackage;
use App\Models\User;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Tata letak form admin: section selebar form, grid responsif, dan form rubrik tetap bisa disimpan. */
class AdminFormLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const LEVELS = [
        'l1' => ['score' => 1, 'label' => 'Belum', 'description' => 'Belum mampu.'],
        'l2' => ['score' => 2, 'label' => 'Mampu', 'description' => 'Mampu dengan bimbingan.'],
        'l3' => ['score' => 3, 'label' => 'Mahir', 'description' => 'Mampu mandiri.'],
    ];

    public function test_layout_components_span_the_full_form_width(): void
    {
        $this->assertSame('full', Section::make('A')->getColumnSpan('default'));
        $this->assertSame('full', Fieldset::make('B')->getColumnSpan('default'));
        $this->assertSame('full', Grid::make(2)->getColumnSpan('default'));
        // Bisa ditimpa per komponen bila memang sengaja berdampingan.
        $this->assertSame(1, Section::make('C')->columnSpan(['default' => 1])->getColumnSpan('default'));
    }

    public function test_layout_helper_collapses_to_one_column_on_small_screens(): void
    {
        $this->assertSame(['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 4], Layout::cols(4));
        $this->assertSame(['default' => 1, 'md' => 3, 'lg' => 3, 'xl' => 6], Layout::cols(6, 3));
        $this->assertSame(['default' => 1, 'md' => 1, 'lg' => 1, 'xl' => 1], Layout::cols(1));
        $this->assertSame(['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 3], Layout::span(3));
        $this->assertSame(['default' => 1, 'md' => 1, 'lg' => 1, 'xl' => 5], Layout::span(5, 1));
    }

    public function test_admin_creates_rubric_with_criteria_and_levels(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $package = TrainingPackage::query()->create(['slug' => 'uji-rubrik', 'title' => 'Uji Rubrik', 'status' => 'published']);
        $this->actingAs($admin);

        $this->get('/admin/assessment-rubrics/create')->assertOk()->assertSee('Kriteria penilaian');

        Livewire::test(CreateAssessmentRubric::class)
            ->fillForm([
                'package_id' => $package->id,
                'name' => 'Rubrik uji',
                'competent_threshold' => 75,
                'description' => 'Keterangan rubrik.',
                'is_active' => true,
                'criteria' => [
                    'c1' => ['code' => 'practice', 'name' => 'Praktik konfigurasi', 'competency' => 'Unit A', 'competency_id' => null, 'weight' => 2, 'max_score' => 3, 'description' => 'Praktik di lab.', 'levels' => self::LEVELS],
                    'c2' => ['code' => 'theory', 'name' => 'Pemahaman teori', 'competency' => null, 'competency_id' => null, 'weight' => 1, 'max_score' => 4, 'description' => null, 'levels' => []],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rubric = AssessmentRubric::query()->where('name', 'Rubrik uji')->firstOrFail();
        $this->assertSame(75, (int) $rubric->competent_threshold);
        $criteria = $rubric->criteria()->orderBy('sort_order')->get();
        $this->assertSame(['Praktik konfigurasi', 'Pemahaman teori'], $criteria->pluck('name')->all());
        $this->assertSame([1, 2], $criteria->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('practice', $criteria[0]->code);
        $this->assertEquals(2, (float) $criteria[0]->weight);
        $this->assertSame(['Belum', 'Mampu', 'Mahir'], array_column($criteria[0]->levels, 'label'));
    }

    public function test_admin_edits_rubric_criteria_and_levels(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $package = TrainingPackage::query()->create(['slug' => 'uji-rubrik-2', 'title' => 'Uji Rubrik 2', 'status' => 'published']);
        $rubric = AssessmentRubric::query()->create(['package_id' => $package->id, 'name' => 'Rubrik lama', 'competent_threshold' => 70, 'is_active' => true]);
        $rubric->criteria()->create(['code' => 'tools', 'name' => 'Alat', 'weight' => 1, 'max_score' => 4, 'levels' => AssessmentRubric::DEFAULT_LEVELS, 'sort_order' => 1]);
        $this->actingAs($admin);

        $this->get("/admin/assessment-rubrics/{$rubric->id}/edit")->assertOk()->assertSee('Deskriptor level (opsional)');

        $page = Livewire::test(EditAssessmentRubric::class, ['record' => $rubric->getRouteKey()])
            ->assertSchemaStateSet(['name' => 'Rubrik lama']);
        $existing = array_key_first($page->get('data.criteria'));
        $this->assertCount(4, $page->get("data.criteria.$existing.levels"));

        // Ubah kriteria yang ada (levels diganti 3 level) dan tambah satu kriteria baru.
        $page->set('data.name', 'Rubrik baru')
            ->set('data.criteria', [
                $existing => ['code' => 'troubleshooting', 'name' => 'Troubleshooting jaringan', 'competency' => 'Unit B', 'competency_id' => null, 'weight' => 3, 'max_score' => 3, 'description' => 'Diagnosa gangguan.', 'levels' => self::LEVELS],
                'baru' => ['code' => 'independence', 'name' => 'Kemandirian', 'competency' => null, 'competency_id' => null, 'weight' => 1, 'max_score' => 4, 'description' => null, 'levels' => []],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $rubric->refresh();
        $this->assertSame('Rubrik baru', $rubric->name);
        $criteria = $rubric->criteria()->orderBy('sort_order')->get();
        $this->assertSame(['Troubleshooting jaringan', 'Kemandirian'], $criteria->pluck('name')->all());
        $this->assertSame([1, 2, 3], array_map('intval', array_column($criteria[0]->levels, 'score')));
    }

    public function test_rubric_requires_level_score_and_label(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $package = TrainingPackage::query()->create(['slug' => 'uji-rubrik-3', 'title' => 'Uji Rubrik 3', 'status' => 'published']);
        $this->actingAs($admin);

        Livewire::test(CreateAssessmentRubric::class)
            ->fillForm([
                'package_id' => $package->id, 'name' => 'Rubrik salah', 'competent_threshold' => 70,
                'criteria' => ['c1' => ['code' => 'custom', 'name' => 'Kriteria', 'weight' => 1, 'max_score' => 4, 'levels' => ['l1' => ['score' => null, 'label' => '', 'description' => null]]]],
            ])
            ->call('create')
            ->assertHasFormErrors(['criteria.c1.levels.l1.score' => 'required', 'criteria.c1.levels.l1.label' => 'required']);

        $this->assertDatabaseMissing('assessment_rubrics', ['name' => 'Rubrik salah']);
    }
}
