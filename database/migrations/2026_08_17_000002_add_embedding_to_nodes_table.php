<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dimensions = (int) config('ollama.embed_dimensions', 768);

        // Схема Laravel не знает типа vector — колонка и индекс только через
        // сырой SQL. nullable по умолчанию: узлы, для которых Ollama был
        // недоступен на момент сохранения (см. EmbeddingService), просто
        // остаются без embedding'а до следующего успешного вызова/бэкафилла.
        DB::statement("ALTER TABLE nodes ADD COLUMN embedding vector({$dimensions})");

        // HNSW — быстрый приближённый поиск ближайших соседей по косинусной
        // дистанции (vector_cosine_ops), тот же оператор <=>, что использует
        // SearchController::semantic()/LinkSuggestionService.
        DB::statement('CREATE INDEX nodes_embedding_hnsw_idx ON nodes USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS nodes_embedding_hnsw_idx');
        DB::statement('ALTER TABLE nodes DROP COLUMN embedding');
    }
};
