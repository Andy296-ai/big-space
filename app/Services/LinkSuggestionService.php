<?php

namespace App\Services;

use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Подсказки связей по смыслу (embedding, pgvector <=>) — считаются на
 * лету по запросу (нет ни очереди, ни scheduled-генерации: см.
 * EmbeddingService класс-комментарий), не персистятся. Персистится только
 * отказ (node_link_dismissals) — чтобы отклонённая пара не всплывала снова.
 */
class LinkSuggestionService
{
    /**
     * Каждая строка — stdClass с id/title/distance (сырой результат
     * DB::table()->get(), см. NodeLinkSuggestionController::index() — там
     * же и уходит клиенту как есть через response()->json()).
     *
     * @return Collection<int, \stdClass>
     */
    public function suggestionsFor(Node $node, int $limit = 5): Collection
    {
        if ($node->embedding === null) {
            return collect();
        }

        $linkedIds = $node->parentEdges()->pluck('parent_id')
            ->merge($node->childEdges()->pluck('child_id'))
            ->push($node->id)
            ->unique();

        $dismissedIds = $this->dismissedPartnerIds($node);
        $threshold = (float) config('ollama.suggestion_distance_threshold');

        return DB::table('nodes')
            ->where('space_id', $node->space_id)
            ->whereNotIn('id', $linkedIds->merge($dismissedIds)->unique())
            ->whereNotNull('embedding')
            ->selectRaw(
                'id, title, embedding <=> (SELECT embedding FROM nodes WHERE id = ?) AS distance',
                [$node->id],
            )
            ->orderBy('distance')
            ->limit($limit)
            ->get()
            ->filter(fn ($row) => $row->distance < $threshold)
            ->values();
    }

    /** Нормализованный порядок (меньший id первым) — см. миграцию node_link_dismissals. */
    public function dismiss(Node $node, Node $other, User $by): void
    {
        [$a, $b] = $node->id < $other->id ? [$node, $other] : [$other, $node];

        DB::table('node_link_dismissals')->insertOrIgnore([
            'space_id' => $node->space_id,
            'node_a_id' => $a->id,
            'node_b_id' => $b->id,
            'dismissed_by' => $by->id,
            'dismissed_at' => now(),
        ]);
    }

    /** @return Collection<int, int> */
    private function dismissedPartnerIds(Node $node): Collection
    {
        $asA = DB::table('node_link_dismissals')->where('node_a_id', $node->id)->pluck('node_b_id');
        $asB = DB::table('node_link_dismissals')->where('node_b_id', $node->id)->pluck('node_a_id');

        return $asA->merge($asB);
    }
}
