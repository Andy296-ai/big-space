<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dimensions = (int) config('ollama.embed_dimensions', 768);

        DB::statement("ALTER TABLE node_attachments ADD COLUMN embedding vector({$dimensions})");
        DB::statement('CREATE INDEX node_attachments_embedding_hnsw_idx ON node_attachments USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS node_attachments_embedding_hnsw_idx');
        DB::statement('ALTER TABLE node_attachments DROP COLUMN embedding');
    }
};
