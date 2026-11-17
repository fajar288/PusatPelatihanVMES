<?php

namespace App\Support\Ai;

use Illuminate\Database\Eloquent\Model;

/**
 * Referensi data sumber sebuah insight: jenis, ID, dan hash isi yang dipakai. Tidak pernah menyimpan salinan data.
 * Hash membuktikan data apa yang dipakai saat itu (bisa dicocokkan ulang) tanpa menyimpan isinya.
 */
final class DataReference
{
    /** @var list<array{type:string,id:int|string|null,hash:string}> */
    private array $items = [];

    /** @param  array<mixed>  $usedData  nilai yang benar-benar dipakai model/aturan dari entitas ini */
    public function add(Model|string $subject, array $usedData = [], int|string|null $id = null): self
    {
        $this->items[] = [
            'type' => $subject instanceof Model ? $subject->getMorphClass() : $subject,
            'id' => $subject instanceof Model ? $subject->getKey() : $id,
            'hash' => self::hash($usedData),
        ];

        return $this;
    }

    /** @return array{items:list<array{type:string,id:int|string|null,hash:string}>,hash:string} */
    public function toArray(): array
    {
        return ['items' => $this->items, 'hash' => self::hash($this->items)];
    }

    /** sha256 dari JSON yang kuncinya diurutkan, jadi urutan kunci tidak mengubah hash. */
    public static function hash(array $data): string
    {
        return hash('sha256', (string) json_encode(self::sortKeys($data), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function sortKeys(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }

        return array_map(fn ($v) => is_array($v) ? self::sortKeys($v) : $v, $data);
    }
}
