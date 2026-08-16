<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Эмбеддинги через локальный Ollama (config('ollama.*')) — без внешнего
 * API и без ключа. Как извлечение текста из PDF/DOCX в
 * AttachmentController — синхронно на запросе, который меняет контент, и
 * try/catch: очередей в проекте нет (см. docs/PROJECT_OVERVIEW.md §3.4),
 * а недоступный Ollama не должен ронять сохранение узла/вложения — узел
 * просто останется без embedding'а до следующего успешного вызова или
 * php artisan nodus:backfill-embeddings.
 */
class EmbeddingService
{
    /** Модели эмбеддингов имеют предел контекста — режем заведомо с запасом, не вплотную к нему. */
    private const MAX_INPUT_CHARS = 8000;

    /** @return array<int, float>|null */
    public function embed(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)->post(rtrim(config('ollama.url'), '/').'/api/embeddings', [
                'model' => config('ollama.embed_model'),
                'prompt' => Str::limit($text, self::MAX_INPUT_CHARS, ''),
            ]);

            if (! $response->successful()) {
                Log::warning('Ollama embeddings request failed.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $embedding = $response->json('embedding');

            return is_array($embedding) && $embedding !== [] ? $embedding : null;
        } catch (\Throwable $e) {
            Log::warning('Failed to generate an embedding via Ollama.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @param  array<int, float>  $embedding */
    public function toVectorLiteral(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }

    /** Единственные таблицы с колонкой embedding — см. миграции 2026_08_17_*. */
    private const EMBEDDABLE_TABLES = ['nodes', 'node_attachments'];

    /**
     * Пишет embedding напрямую SQL'ем (не $model->update()) — колонка
     * умышленно вне $fillable/видимости модели, писать в неё положено
     * только отсюда, см. класс-комментарий Node/NodeAttachment. $table —
     * не пользовательский ввод нигде по коду, но раз уж оно подставляется
     * прямо в SQL, сверяем с фиксированным списком, а не доверяем вызывающему.
     *
     * @param  array<int, float>|null  $embedding
     */
    public function store(string $table, int $id, ?array $embedding): void
    {
        if ($embedding === null) {
            return;
        }

        if (! in_array($table, self::EMBEDDABLE_TABLES, true)) {
            throw new \InvalidArgumentException("Unknown embeddable table: {$table}");
        }

        DB::statement(
            "UPDATE {$table} SET embedding = ?::vector WHERE id = ?",
            [$this->toVectorLiteral($embedding), $id],
        );
    }
}
