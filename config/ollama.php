<?php

return [

    /**
     * Локальный сервер Ollama — эмбеддинги для семантического поиска и
     * подсказок связей считаются полностью на этой машине, без внешнего
     * API и без ключа (см. app/Services/EmbeddingService.php).
     */
    'url' => env('OLLAMA_URL', 'http://localhost:11434'),

    /**
     * nomic-embed-text — 274 МБ, 768 измерений, установлен командой
     * `ollama pull nomic-embed-text`. Меняя модель, обязательно меняйте
     * embed_dimensions и переиндексируйте существующие embedding'и
     * (php artisan nodus:backfill-embeddings) — размерность вектора,
     * записанного в колонку vector(N), фиксирована миграцией.
     */
    'embed_model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),
    'embed_dimensions' => env('OLLAMA_EMBED_DIMENSIONS', 768),

    /**
     * Косинусная дистанция (0 — идентичны, 2 — противоположны). Результаты
     * дальше порога отбрасываются — иначе семантический поиск при
     * отсутствии по-настоящему близких узлов возвращал бы просто самые
     * менее далёкие из всех, а не действительно релевантные.
     */
    'search_distance_threshold' => env('OLLAMA_SEARCH_THRESHOLD', 0.6),
    'suggestion_distance_threshold' => env('OLLAMA_SUGGESTION_THRESHOLD', 0.5),

];
