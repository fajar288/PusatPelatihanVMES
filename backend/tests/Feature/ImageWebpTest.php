<?php

namespace Tests\Feature;

use App\Filament\Resources\Galleries\Pages\CreateGallery;
use App\Models\Gallery;
use App\Models\User;
use App\Support\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Kompresi gambar CMS di server: diperkecil dan disimpan sebagai WebP (Fields::image), GIF/SVG apa adanya. */
class ImageWebpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ImageProcessor::supported()) {
            $this->markTestSkipped('GD dengan dukungan WebP tidak tersedia.');
        }
        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    private function createGallery(UploadedFile $file, string $title): Gallery
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->actingAs($admin)->get('/admin/galleries/create')->assertOk();
        Livewire::test(CreateGallery::class)
            ->fillForm(['title' => $title, 'image' => $file, 'is_active' => true, 'sort_order' => 0])
            ->call('create')
            ->assertHasNoFormErrors();

        return Gallery::query()->where('title', $title)->firstOrFail();
    }

    public function test_large_jpeg_is_resized_and_stored_as_webp(): void
    {
        $file = UploadedFile::fake()->image('kegiatan.jpg', 2400, 1600);
        $gallery = $this->createGallery($file, 'Kegiatan uji');

        $this->assertStringStartsWith('galleries/', $gallery->image);
        $this->assertStringEndsWith('.webp', $gallery->image);
        $bytes = Storage::disk('public')->get($gallery->image);
        [$w, $h, $type] = getimagesizefromstring($bytes);
        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame([1600, 1067], [$w, $h], 'sisi terpanjang dibatasi 1600 px, rasio tetap');
        $this->assertLessThan($file->getSize(), strlen($bytes));
    }

    public function test_gif_is_kept_as_is(): void
    {
        $gallery = $this->createGallery(UploadedFile::fake()->image('animasi.gif', 300, 200), 'Animasi uji');

        $this->assertStringEndsWith('.gif', $gallery->image);
        Storage::disk('public')->assertExists($gallery->image);
    }

    public function test_processor_keeps_transparency_and_small_images_are_not_upscaled(): void
    {
        $img = imagecreatetruecolor(120, 60);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, 10, 10, 50, 50, imagecolorallocate($img, 0, 75, 35));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        $webp = ImageProcessor::toWebp($png, 1600);
        $this->assertNotNull($webp);
        $out = imagecreatefromstring($webp);
        $this->assertSame([120, 60], [imagesx($out), imagesy($out)]);
        $this->assertSame(127, (imagecolorat($out, 100, 5) >> 24) & 0x7F, 'piksel transparan tetap transparan');
        $this->assertNull(ImageProcessor::toWebp('bukan gambar', 1600));
    }
}
