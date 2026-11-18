<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\MemberService;
use App\Support\ImageProcessor;
use Database\Seeders\RolesSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Foto profil member (PRD: profil member memuat foto): privat, dikompresi, hanya pemilik & admin. */
class MemberPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    private function member(string $email = 'foto@example.com'): User
    {
        $user = User::factory()->create(['email' => $email]);
        app(MemberService::class)->createForUser($user);

        return $user->refresh();
    }

    public function test_member_uploads_photo_which_is_cropped_to_square_webp_and_stored_privately(): void
    {
        $user = $this->member();
        $res = $this->actingAs($user, 'sanctum')->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('wajah.jpg', 1200, 800)], ['Accept' => 'application/json'])
            ->assertOk();
        $version = $res->json('data.member.photoVersion');
        $this->assertNotNull($version);
        $this->assertArrayNotHasKey('photo', $res->json('data.member'), 'URL/path foto tidak boleh dikirim');

        $path = $user->member->refresh()->photo;
        Storage::disk('local')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        if (ImageProcessor::supported()) {
            $this->assertStringEndsWith('.webp', $path);
            [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($path));
            $this->assertSame([MemberService::PHOTO_SIZE, MemberService::PHOTO_SIZE], [$w, $h]);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.photo_updated']);

        // pemilik bisa mengambil fotonya, tamu tidak
        $this->actingAs($user, 'sanctum')->get('/api/v1/me/photo')->assertOk()->assertHeader('Cache-Control', 'max-age=86400, private');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me/photo')->assertUnauthorized();
    }

    public function test_replacing_and_removing_photo_cleans_up_old_files(): void
    {
        $user = $this->member();
        $this->actingAs($user, 'sanctum')->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('a.png', 300, 300)], ['Accept' => 'application/json'])->assertOk();
        $first = $user->member->refresh()->photo;
        $v1 = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->json('data.member.photoVersion');

        $this->actingAs($user, 'sanctum')->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('b.jpg', 500, 500)], ['Accept' => 'application/json'])->assertOk();
        $second = $user->member->refresh()->photo;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        $this->assertNotSame($v1, $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->json('data.member.photoVersion'));

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/me/photo')->assertOk()->assertJsonPath('data.member.photoVersion', null);
        Storage::disk('local')->assertMissing($second);
        $this->assertNull($user->member->refresh()->photo);
        $this->actingAs($user, 'sanctum')->get('/api/v1/me/photo')->assertNotFound();
    }

    public function test_photo_validation(): void
    {
        $user = $this->member();
        $post = fn ($file) => $this->actingAs($user, 'sanctum')->post('/api/v1/me/photo', ['photo' => $file], ['Accept' => 'application/json']);
        $post(UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('photo');
        $post(UploadedFile::fake()->image('kecil.jpg', 50, 50))->assertUnprocessable()->assertJsonValidationErrors('photo');
        $post(UploadedFile::fake()->image('besar.jpg', 400, 400)->size(5000))->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertNull($user->member->refresh()->photo);
    }

    public function test_admin_sees_photo_through_signed_url(): void
    {
        // disk lokal sungguhan agar URL sementara bertanda tangan yang asli ikut diuji
        $root = storage_path('framework/testing/disks/member-photo');
        config(['filesystems.disks.local.root' => $root, 'filesystems.disks.local.serve' => true]);
        Storage::forgetDisk('local');
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($root));

        $user = $this->member();
        app(MemberService::class)->updatePhoto($user->member, UploadedFile::fake()->image('w.jpg', 300, 300));
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $html = $this->actingAs($admin)->get("/admin/peserta/{$user->member->member_number}")->assertOk()->getContent();
        preg_match('#src="([^"]*members/photos[^"]*)"#', $html, $m);
        $this->assertNotEmpty($m, 'foto tampil di detail member');
        $url = html_entity_decode($m[1]);
        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk();
    }
}
