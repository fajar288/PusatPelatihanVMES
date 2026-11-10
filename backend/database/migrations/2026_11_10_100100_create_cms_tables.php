<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema CMS (PRD §5.2): partner/principal, testimoni, galeri, postingan, halaman statis, menu, banner, pengaturan.
 *
 * - `partners.type` membedakan client | principal | certification (menggantikan tabel `principals` terpisah).
 * - `posts.type`: tutorial | news | activity | documentation (kategori tetap seperti website lama).
 * - `site_settings`: key → value JSON (identitas situs, hero, statistik, tentang, mengapa, CTA, profil perusahaan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->unsignedSmallInteger('width')->nullable()->comment('dimensi asli logo (untuk next/image)');
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('website')->nullable();
            $table->string('type', 30)->default('client')->comment('client|principal|certification');
            $table->text('description')->nullable();
            $table->boolean('is_official')->default(false)->comment('tampil di kartu "Partner Resmi" hero');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('testimonials', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('photo')->nullable();
            $table->string('position')->nullable();
            $table->string('company')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('program')->nullable();
            $table->foreignId('package_id')->nullable()->constrained('training_packages')->nullOnDelete();
            $table->text('content');
            $table->unsignedTinyInteger('rating')->default(5);
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('galleries', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('category', 60)->nullable();
            $table->string('image');
            $table->string('caption')->nullable();
            $table->date('event_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 30)->default('news')->comment('tutorial|news|activity|documentation');
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable()->comment('HTML');
            $table->string('thumbnail')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name')->nullable();
            $table->string('status', 20)->default('draft')->comment('draft|published|archived');
            $table->timestamp('published_at')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['type', 'status', 'published_at']);
        });

        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('body')->nullable()->comment('HTML');
            $table->string('status', 20)->default('published');
            $table->timestamp('published_at')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();
        });

        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->string('location', 40)->comment('header|footer_1|footer_2|footer_3');
            $table->string('group_title')->nullable()->comment('judul kolom footer');
            $table->foreignId('parent_id')->nullable()->constrained('menus')->nullOnDelete();
            $table->string('label');
            $table->string('url');
            $table->boolean('is_external')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['location', 'is_active']);
        });

        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->string('position', 40)->default('hero');
            $table->string('title')->nullable();
            $table->string('image');
            $table->string('alt')->nullable();
            $table->string('link')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['position', 'is_active']);
        });

        Schema::create('site_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->json('value')->nullable();
            $table->string('group', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('galleries');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('partners');
    }
};
