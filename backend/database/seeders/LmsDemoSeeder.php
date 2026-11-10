<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Models\QuizQuestion;
use App\Models\TrainingPackage;
use Illuminate\Database\Seeder;

/**
 * Contoh course LMS untuk paket Cisco CCNA (lokal/demo saja; idempoten berdasarkan slug).
 * Jalankan: php artisan db:seed --class=LmsDemoSeeder
 */
class LmsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $package = TrainingPackage::query()->where('slug', 'cisco-ccna')->first() ?? TrainingPackage::query()->first();
        if (! $package) {
            $this->command?->warn('Belum ada paket training; jalankan CmsSeeder dulu.');

            return;
        }
        $course = Course::query()->updateOrCreate(['slug' => 'ccna-online-course'], [
            'package_id' => $package->id, 'title' => 'CCNA Online Course', 'is_published' => true, 'available_before_class' => true,
            'description' => 'Materi pendamping kelas CCNA: dasar jaringan, routing & switching, kuis dan tugas praktik.',
        ]);
        if ($course->modules()->exists()) {
            return;
        }

        $m1 = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Dasar Jaringan', 'description' => 'Model OSI/TCP-IP, pengalamatan IP, dan perangkat jaringan.', 'sort_order' => 1]);
        $intro = Lesson::query()->create(['module_id' => $m1->id, 'title' => 'Selamat datang & cara belajar', 'type' => 'text', 'sort_order' => 1, 'duration_minutes' => 5,
            'body' => '<p>Selamat datang di <strong>CCNA Online Course</strong>. Pelajari materi berurutan, tonton video, kerjakan kuis, dan kumpulkan tugas sebelum batas waktu.</p><ul><li>Materi teks &amp; dokumen otomatis tercatat selesai saat dibuka.</li><li>Video dianggap selesai setelah ditonton minimal 80%.</li><li>Kuis dinilai otomatis; tugas dinilai trainer.</li></ul>']);
        LessonResource::query()->create(['lesson_id' => $intro->id, 'type' => 'link', 'title' => 'Silabus CCNA (Cisco)', 'url' => 'https://www.cisco.com/site/us/en/learn/training-certifications/certifications/enterprise/ccna/index.html', 'sort_order' => 1]);
        Lesson::query()->create(['module_id' => $m1->id, 'title' => 'Video: Model OSI dalam 10 menit', 'type' => 'video', 'sort_order' => 2, 'duration_minutes' => 10,
            'settings' => ['video_url' => 'https://www.youtube.com/watch?v=vv4y_uOneC0', 'duration_seconds' => 600, 'min_watch_pct' => 80], 'body' => '<p>Tujuh lapisan OSI dan fungsinya.</p>']);
        Lesson::query()->create(['module_id' => $m1->id, 'title' => 'E-book: Pengalamatan IPv4', 'type' => 'document', 'sort_order' => 3, 'duration_minutes' => 20,
            'body' => '<p>Unggah berkas PDF/PPT melalui panel admin pada tab <em>Berkas &amp; tautan</em> lesson ini.</p>']);
        $quiz = Lesson::query()->create(['module_id' => $m1->id, 'title' => 'Pre-test Dasar Jaringan', 'type' => 'quiz', 'sort_order' => 4, 'duration_minutes' => 10,
            'settings' => ['kind' => 'pre_test', 'passing_score' => 60, 'max_attempts' => 2, 'time_limit_minutes' => 10, 'show_answers' => true]]);
        QuizQuestion::query()->create(['lesson_id' => $quiz->id, 'type' => 'single', 'question' => 'Lapisan OSI yang bertanggung jawab atas pengalamatan logis (IP) adalah…', 'options' => [['key' => 'a', 'text' => 'Data Link'], ['key' => 'b', 'text' => 'Network'], ['key' => 'c', 'text' => 'Transport'], ['key' => 'd', 'text' => 'Session']], 'answer' => ['b'], 'explanation' => 'Layer 3 (Network) menangani pengalamatan logis dan routing.', 'points' => 1, 'sort_order' => 1]);
        QuizQuestion::query()->create(['lesson_id' => $quiz->id, 'type' => 'multiple', 'question' => 'Protokol yang bekerja di lapisan Transport:', 'options' => [['key' => 'a', 'text' => 'TCP'], ['key' => 'b', 'text' => 'IP'], ['key' => 'c', 'text' => 'UDP'], ['key' => 'd', 'text' => 'ARP']], 'answer' => ['a', 'c'], 'points' => 2, 'sort_order' => 2]);
        QuizQuestion::query()->create(['lesson_id' => $quiz->id, 'type' => 'true_false', 'question' => 'Switch bekerja di lapisan 2 berdasarkan MAC address.', 'answer' => ['true'], 'points' => 1, 'sort_order' => 3]);
        QuizQuestion::query()->create(['lesson_id' => $quiz->id, 'type' => 'short', 'question' => 'Berapa jumlah bit alamat IPv4?', 'answer' => ['32', '32 bit'], 'points' => 1, 'sort_order' => 4]);

        $m2 = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Routing & Switching', 'description' => 'Konfigurasi dasar router/switch, VLAN, dan static routing.', 'sort_order' => 2]);
        Lesson::query()->create(['module_id' => $m2->id, 'title' => 'Konfigurasi dasar IOS', 'type' => 'text', 'sort_order' => 1, 'duration_minutes' => 15,
            'body' => '<p>Mode <code>enable</code>, <code>configure terminal</code>, pengaturan hostname, password, dan penyimpanan konfigurasi.</p>']);
        Lesson::query()->create(['module_id' => $m2->id, 'title' => 'Tugas: Desain subnet kantor cabang', 'type' => 'assignment', 'sort_order' => 2, 'duration_minutes' => 60,
            'settings' => ['max_score' => 100, 'allow_file' => true, 'allow_text' => true, 'due_at' => now()->addDays(14)->setTime(23, 59)->toDateTimeString()],
            'body' => '<p>Buat rencana subnetting untuk 4 departemen (20, 50, 10, 120 host) dari blok 192.168.10.0/24 dan lampirkan tabel dalam PDF/XLSX.</p>']);
        Lesson::query()->create(['module_id' => $m2->id, 'title' => 'Post-test Routing & Switching', 'type' => 'quiz', 'sort_order' => 3, 'is_required' => true,
            'settings' => ['kind' => 'post_test', 'passing_score' => 70, 'max_attempts' => 3]])
            ->questions()->create(['type' => 'single', 'question' => 'Perintah untuk masuk mode konfigurasi global:', 'options' => [['key' => 'a', 'text' => 'enable'], ['key' => 'b', 'text' => 'configure terminal'], ['key' => 'c', 'text' => 'show running-config']], 'answer' => ['b'], 'points' => 1, 'sort_order' => 1]);
    }
}
