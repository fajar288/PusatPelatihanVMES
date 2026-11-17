<?php

namespace App\Support\Ai\Evaluators;

/**
 * Evaluasi khusus satu use case untuk ai:evaluate: metrik sesuai tabel metrik use case, dibandingkan dengan baseline.
 * Didaftarkan di AiEvaluationService::EVALUATORS.
 */
interface Evaluator
{
    /**
     * @return array{metrics: array<string, mixed>, baseline: array<string, mixed>|null, notes: list<string>}
     */
    public function evaluate(): array;
}
