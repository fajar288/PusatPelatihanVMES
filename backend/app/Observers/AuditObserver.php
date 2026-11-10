<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use Illuminate\Database\Eloquent\Model;

/** Jejak audit otomatis untuk perubahan dari panel admin (created/updated/deleted). Aksi domain dicatat eksplisit oleh service. */
class AuditObserver
{
    public const MODELS = [Registration::class, Payment::class, Member::class, TrainingSchedule::class];

    private const IGNORE = ['updated_at', 'created_at', 'remember_token', 'password'];

    public function created(Model $model): void
    {
        if ($model instanceof Registration || $model instanceof Member) {
            return; // sudah dicatat oleh service dengan konteks aksi
        }
        AuditLog::record(class_basename($model).'.created', $model, null, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->clean($model->getChanges());
        if ($changes === []) {
            return;
        }
        $before = array_intersect_key($model->getOriginal(), $changes);
        AuditLog::record(class_basename($model).'.updated', $model, $this->clean($before), $changes);
    }

    public function deleted(Model $model): void
    {
        AuditLog::record(class_basename($model).'.deleted', $model, $this->clean($model->getOriginal()), null);
    }

    /** @param  array<string, mixed>  $attrs  @return array<string, mixed> */
    private function clean(array $attrs): array
    {
        $out = array_diff_key($attrs, array_flip(self::IGNORE));
        foreach ($out as $k => $v) {
            if ($v instanceof \BackedEnum) {
                $out[$k] = $v->value;
            } elseif ($v instanceof \DateTimeInterface) {
                $out[$k] = $v->format(DATE_ATOM);
            }
        }

        return $out;
    }
}
