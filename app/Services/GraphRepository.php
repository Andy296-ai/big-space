<?php

namespace App\Services;

use App\Events\SpaceUpdated;
use App\Models\Edge;
use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Space;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GraphRepository
{
    /**
     * Checks whether a parent -> child link is allowed by the space's structure.
     * Returns a machine-readable reason code, or null when the link is valid.
     */
    public function validateLink(Space $space, int $parentId, int $childId): ?string
    {
        if ($parentId === $childId) {
            return 'self_link';
        }

        $alreadyLinked = Edge::where('space_id', $space->id)
            ->where('parent_id', $parentId)
            ->where('child_id', $childId)
            ->exists();

        if ($alreadyLinked) {
            return null; // Повторный вызов ничего не меняет.
        }

        $childHasParent = Edge::where('space_id', $space->id)
            ->where('child_id', $childId)
            ->exists();

        if (! $space->allowsMultipleParents() && $childHasParent) {
            return 'single_parent';
        }

        if (! $space->allowsCycles() && $this->wouldCreateCycle($space->id, $parentId, $childId)) {
            return 'cycle';
        }

        return null;
    }

    /**
     * Checks whether an existing graph can be re-declared as the given structure.
     * Returns ['reason' => ..., 'conflicts' => int] or null when the switch is safe.
     *
     * @return array{reason: string, conflicts: int}|null
     */
    public function structureConflicts(Space $space, string $structure): ?array
    {
        if ($structure === Space::STRUCTURE_TREE) {
            $multiParent = $this->nodesWithMultipleParents($space->id);

            if (! empty($multiParent)) {
                return ['reason' => 'single_parent', 'conflicts' => count($multiParent)];
            }
        }

        if ($structure !== Space::STRUCTURE_NETWORK && $this->hasCycle($space->id)) {
            return ['reason' => 'cycle', 'conflicts' => 1];
        }

        if ($structure === Space::STRUCTURE_LEVELED) {
            // Глубины пересчитываем от корней: большинство графов после этого
            // укладывается в уровни само, и переключение проходит без правок.
            $gaps = $this->levelGaps($space->id, $this->computeLevelDepths($space->id));

            if ($gaps > 0) {
                return ['reason' => 'level_gap', 'conflicts' => $gaps];
            }
        }

        return null;
    }

    /**
     * Глубина каждого узла как расстояние от ближайшего корня.
     *
     * @return array<int, int>
     */
    public function computeLevelDepths(int $spaceId): array
    {
        $nodeIds = Node::where('space_id', $spaceId)->pluck('id')->all();
        $edges = Edge::where('space_id', $spaceId)->get(['parent_id', 'child_id']);

        $childrenOf = [];
        $hasParent = [];

        foreach ($edges as $e) {
            $childrenOf[$e->parent_id][] = $e->child_id;
            $hasParent[$e->child_id] = true;
        }

        $depths = [];
        $queue = [];

        foreach ($nodeIds as $id) {
            if (! isset($hasParent[$id])) {
                $depths[$id] = 0;
                $queue[] = $id;
            }
        }

        // Обход в ширину — очередь растёт по ходу, поэтому обычный for.
        for ($i = 0; $i < count($queue); $i++) {
            $current = $queue[$i];

            foreach ($childrenOf[$current] ?? [] as $childId) {
                if (! isset($depths[$childId])) {
                    $depths[$childId] = $depths[$current] + 1;
                    $queue[] = $childId;
                }
            }
        }

        // Узел, до которого не добрались (например, внутри цикла), остаётся на нуле.
        foreach ($nodeIds as $id) {
            $depths[$id] ??= 0;
        }

        return $depths;
    }

    /**
     * Сколько рёбер перепрыгивают уровень при заданных глубинах.
     *
     * @param  array<int, int>  $depths
     */
    public function levelGaps(int $spaceId, array $depths): int
    {
        $gaps = 0;

        foreach (Edge::where('space_id', $spaceId)->get(['parent_id', 'child_id']) as $e) {
            $parentDepth = $depths[$e->parent_id] ?? null;
            $childDepth = $depths[$e->child_id] ?? null;

            if ($parentDepth === null || $childDepth === null || $childDepth !== $parentDepth + 1) {
                $gaps++;
            }
        }

        return $gaps;
    }

    /**
     * Подбирает глубины так, чтобы новое ребро уложилось в соседние уровни:
     * ребёнок вместе со всем поддеревом сдвигается под родителя. Возвращает
     * только изменившиеся глубины, либо null, если сдвиг ломает другие рёбра.
     *
     * @return array<int, int>|null
     */
    public function planLevelShift(Space $space, int $parentId, int $childId): ?array
    {
        $depths = [];
        foreach (Node::where('space_id', $space->id)->get(['id', 'depth']) as $node) {
            $depths[$node->id] = $node->depth;
        }

        if (! isset($depths[$parentId], $depths[$childId])) {
            return null;
        }

        $edges = Edge::where('space_id', $space->id)->get(['parent_id', 'child_id']);

        $childrenOf = [];
        foreach ($edges as $e) {
            $childrenOf[$e->parent_id][] = $e->child_id;
        }

        // Поддерево, которое поедет вместе с ребёнком.
        $moving = [$childId => true];
        $queue = [$childId];

        while (! empty($queue)) {
            $current = array_pop($queue);

            foreach ($childrenOf[$current] ?? [] as $next) {
                if (! isset($moving[$next])) {
                    $moving[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        if (isset($moving[$parentId])) {
            return null; // Родитель внутри поддерева — связь замкнула бы цикл.
        }

        $delta = ($depths[$parentId] + 1) - $depths[$childId];
        $planned = $depths;

        foreach (array_keys($moving) as $id) {
            $planned[$id] += $delta;

            if ($planned[$id] < 0) {
                return null;
            }
        }

        // Новые глубины должны устроить все рёбра, включая добавляемое.
        $pairs = $edges->map(fn ($e) => [$e->parent_id, $e->child_id])->all();
        $pairs[] = [$parentId, $childId];

        foreach ($pairs as [$p, $c]) {
            if (! isset($planned[$p], $planned[$c]) || $planned[$c] !== $planned[$p] + 1) {
                return null;
            }
        }

        return array_filter(
            $planned,
            fn (int $depth, int $id) => $depth !== $depths[$id],
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<int, int>  $depths
     */
    public function applyDepths(int $spaceId, array $depths): void
    {
        if (empty($depths)) {
            return;
        }

        // Один UPDATE на новое значение depth, а не на узел — сдвиг обычно
        // переносит целое поддерево на одну и ту же дельту, так что группы
        // получаются намного меньше $depths целиком.
        $idsByDepth = [];
        foreach ($depths as $id => $depth) {
            $idsByDepth[$depth][] = $id;
        }

        foreach ($idsByDepth as $depth => $ids) {
            Node::where('space_id', $spaceId)->whereIn('id', $ids)->update(['depth' => $depth]);
        }

        // Query-builder update() не будит модельные события — сигналим о живом обновлении сами.
        SpaceUpdated::dispatch($spaceId);
    }

    /**
     * IDs of nodes reachable from more than one parent edge.
     *
     * @return array<int, int>
     */
    public function nodesWithMultipleParents(int $spaceId): array
    {
        return Edge::where('space_id', $spaceId)
            ->groupBy('child_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('child_id')
            ->all();
    }

    /**
     * Detects whether the space already contains a cycle.
     */
    public function hasCycle(int $spaceId): bool
    {
        $edges = Edge::where('space_id', $spaceId)->get(['parent_id', 'child_id']);

        $childrenOf = [];
        foreach ($edges as $e) {
            $childrenOf[$e->parent_id][] = $e->child_id;
        }

        // Итеративный обход в глубину: 1 — узел в текущем пути, 2 — уже разобран.
        $state = [];

        foreach (Node::where('space_id', $spaceId)->pluck('id') as $start) {
            if (isset($state[$start])) {
                continue;
            }

            $state[$start] = 1;
            $stack = [[$start, 0]];

            while (! empty($stack)) {
                $top = count($stack) - 1;
                [$current, $childIndex] = $stack[$top];
                $children = $childrenOf[$current] ?? [];

                if ($childIndex >= count($children)) {
                    $state[$current] = 2;
                    array_pop($stack);

                    continue;
                }

                $stack[$top][1] = $childIndex + 1;
                $childId = $children[$childIndex];

                if (($state[$childId] ?? 0) === 1) {
                    return true; // Ребро ведёт назад в текущий путь.
                }

                if (! isset($state[$childId])) {
                    $state[$childId] = 1;
                    $stack[] = [$childId, 0];
                }
            }
        }

        return false;
    }

    /**
     * Deletion set honouring the space structure. A network has no roots to
     * measure reachability from, so nothing cascades there.
     *
     * @param  array<int, int>  $targetIds
     * @return array<int, int>
     */
    public function computeDeletionSetForSpace(Space $space, array $targetIds): array
    {
        if ($space->allowsCycles()) {
            return array_values(array_unique($targetIds));
        }

        return $this->computeDeletionSetMany($space->id, $targetIds);
    }

    /**
     * Checks if adding an edge parent_id -> child_id would introduce a cycle.
     */
    public function wouldCreateCycle(int $spaceId, int $parentId, int $childId): bool
    {
        if ($parentId === $childId) {
            return true;
        }

        // BFS: check if parentId is reachable from childId following existing child edges
        $visited = [];
        $queue = [$childId];
        $visited[$childId] = true;

        $edges = Edge::where('space_id', $spaceId)->get(['parent_id', 'child_id']);
        $childrenOf = [];
        foreach ($edges as $e) {
            $childrenOf[$e->parent_id][] = $e->child_id;
        }

        while (! empty($queue)) {
            $current = array_pop($queue);
            if ($current === $parentId) {
                return true;
            }

            if (isset($childrenOf[$current])) {
                foreach ($childrenOf[$current] as $cId) {
                    if (! isset($visited[$cId])) {
                        $visited[$cId] = true;
                        $queue[] = $cId;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Computes the list of node IDs that would become unreachable from all root nodes if targetId is removed.
     *
     * @return array<int, int>
     */
    public function computeDeletionSet(int $spaceId, int $targetId): array
    {
        return $this->computeDeletionSetMany($spaceId, [$targetId]);
    }

    /**
     * Union of computeDeletionSet for multiple targets.
     *
     * @param  array<int, int>  $targetIds
     * @return array<int, int>
     */
    public function computeDeletionSetMany(int $spaceId, array $targetIds): array
    {
        $allNodes = Node::where('space_id', $spaceId)->pluck('id')->toArray();
        $edges = Edge::where('space_id', $spaceId)->get(['parent_id', 'child_id']);

        $childrenOf = [];
        $parentsOf = [];
        $hasParent = [];

        foreach ($edges as $e) {
            $childrenOf[$e->parent_id][] = $e->child_id;
            $parentsOf[$e->child_id][] = $e->parent_id;
            $hasParent[$e->child_id] = true;
        }

        $targetsSet = array_flip($targetIds);

        // Find all roots (nodes with no parents) that are NOT in targetIds
        $remainingRoots = [];
        foreach ($allNodes as $id) {
            if (! isset($hasParent[$id]) && ! isset($targetsSet[$id])) {
                $remainingRoots[] = $id;
            }
        }

        // Reachable nodes from remaining roots
        $reachable = [];
        $queue = $remainingRoots;
        foreach ($remainingRoots as $r) {
            $reachable[$r] = true;
        }

        while (! empty($queue)) {
            $curr = array_pop($queue);
            if (isset($childrenOf[$curr])) {
                foreach ($childrenOf[$curr] as $childId) {
                    if (! isset($targetsSet[$childId]) && ! isset($reachable[$childId])) {
                        $reachable[$childId] = true;
                        $queue[] = $childId;
                    }
                }
            }
        }

        // Nodes to delete = allNodes - reachable
        $toDelete = [];
        foreach ($allNodes as $id) {
            if (! isset($reachable[$id])) {
                $toDelete[] = $id;
            }
        }

        return $toDelete;
    }

    /**
     * Assigns/recalculates tree_root_id for nodes in a space.
     */
    public function updateTreeRootIds(int $spaceId): void
    {
        $nodes = Node::where('space_id', $spaceId)->pluck('id')->toArray();
        $edges = Edge::where('space_id', $spaceId)->get(['parent_id', 'child_id']);

        $childrenOf = [];
        $hasParent = [];
        foreach ($edges as $e) {
            $childrenOf[$e->parent_id][] = $e->child_id;
            $hasParent[$e->child_id] = true;
        }

        $visited = [];
        foreach ($nodes as $id) {
            if (isset($hasParent[$id])) {
                continue; // Not a root
            }

            // Сначала собираем всё поддерево в памяти, потом один UPDATE на
            // корень — вместо запроса на каждый узел по ходу обхода.
            $subtreeIds = [];
            $queue = [$id];
            $visited[$id] = true;

            while (! empty($queue)) {
                $current = array_pop($queue);
                $subtreeIds[] = $current;

                if (isset($childrenOf[$current])) {
                    foreach ($childrenOf[$current] as $childId) {
                        if (! isset($visited[$childId])) {
                            $visited[$childId] = true;
                            $queue[] = $childId;
                        }
                    }
                }
            }

            Node::whereIn('id', $subtreeIds)->update(['tree_root_id' => $id]);
        }

        // Fallback for orphaned/cyclic nodes
        Node::where('space_id', $spaceId)->whereNull('tree_root_id')->update([
            'tree_root_id' => DB::raw('id'),
        ]);
    }

    /**
     * Настоящие потомки узла — BFS вдоль дочерних рёбер от rootId, включая
     * сам rootId. Это ДРУГОЙ вопрос, чем computeDeletionSetForSpace()
     * ("что станет недостижимым от остальных корней") — они расходятся,
     * как только у узла есть второй родитель (dag/network/leveled, см.
     * Space::allowsMultipleParents()), поэтому для "поддерево для шеринга"
     * нужен именно этот метод, а не тот.
     *
     * @return array<int, int> id узлов поддерева (включая корень)
     */
    public function collectSubtreeIds(int $spaceId, int $rootId): array
    {
        $edges = Edge::where('space_id', $spaceId)->get(['parent_id', 'child_id']);

        $childrenOf = [];
        foreach ($edges as $e) {
            $childrenOf[$e->parent_id][] = $e->child_id;
        }

        $visited = [$rootId => true];
        $order = [$rootId];
        $queue = [$rootId];

        while (! empty($queue)) {
            $current = array_shift($queue);

            foreach ($childrenOf[$current] ?? [] as $childId) {
                if (! isset($visited[$childId])) {
                    $visited[$childId] = true;
                    $order[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $order;
    }

    /**
     * Обход для экспорта в Markdown/PDF — pre-order DFS от каждого корня
     * (узел без родителя), с глубиной для заголовков. НЕ переиспользует
     * SpaceController::export()'s JSON-логику — та плоский список с
     * ремаппингом id для обратного импорта, а не обход parent→children.
     * Явный visited-guard обязателен, не опционален: network-пространства
     * допускают циклы (см. Space::allowsCycles()), наивная рекурсия без
     * него зависла бы навсегда.
     *
     * @return array<int, array{node: Node, depth: int}>
     */
    public function walkForExport(int $spaceId): array
    {
        $nodes = Node::where('space_id', $spaceId)->with('attachments')->get()->keyBy('id');
        $edges = Edge::where('space_id', $spaceId)->orderBy('id')->get(['parent_id', 'child_id']);

        $childrenOf = [];
        $hasParent = [];

        foreach ($edges as $e) {
            $childrenOf[$e->parent_id][] = $e->child_id;
            $hasParent[$e->child_id] = true;
        }

        $roots = $nodes->keys()->reject(fn (int $id) => $hasParent[$id] ?? false)->values();

        // Цикл без единого "внешнего" корня в принципе возможен в network —
        // тогда стартуем с первого узла по id, чтобы вообще что-то выгрузить,
        // а не вернуть пустой документ.
        if ($roots->isEmpty() && $nodes->isNotEmpty()) {
            $roots = collect([$nodes->keys()->first()]);
        }

        $visited = [];
        $ordered = [];

        foreach ($roots as $rootId) {
            $stack = [[$rootId, 0]];

            while ($stack !== []) {
                [$id, $depth] = array_pop($stack);

                if (isset($visited[$id]) || ! $nodes->has($id)) {
                    continue;
                }

                $visited[$id] = true;
                $ordered[] = ['node' => $nodes->get($id), 'depth' => $depth];

                // В обратном порядке — после array_pop() дети всплывают в исходной последовательности, не задом наперёд.
                foreach (array_reverse($childrenOf[$id] ?? []) as $childId) {
                    $stack[] = [$childId, $depth + 1];
                }
            }
        }

        return $ordered;
    }

    /**
     * Клонирует узел вместе со всем его поддеревом (и вложениями) как новые
     * записи, опционально подвешивая копию под другого родителя. Родитель
     * может быть любым узлом пространства — не обязательно корнем и не
     * обязательно вне копируемого поддерева, цикл здесь в принципе невозможен:
     * копия всегда состоит из свежих id.
     */
    public function copySubtree(Space $space, Node $sourceRoot, ?Node $newParent): Node
    {
        $edges = Edge::where('space_id', $space->id)->get(['parent_id', 'child_id']);

        $childrenOf = [];
        foreach ($edges as $e) {
            $childrenOf[$e->parent_id][] = $e->child_id;
        }

        // Обход в ширину от копируемого узла: относительная глубина — это
        // «сколько рёбер от корня копии», по ней глубины пересчитываются
        // заново под новым родителем, где бы тот ни находился.
        $relativeDepth = [$sourceRoot->id => 0];
        $order = [$sourceRoot->id];
        $queue = [$sourceRoot->id];

        while (! empty($queue)) {
            $current = array_shift($queue);

            foreach ($childrenOf[$current] ?? [] as $childId) {
                if (! isset($relativeDepth[$childId])) {
                    $relativeDepth[$childId] = $relativeDepth[$current] + 1;
                    $order[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        $subtreeIds = array_flip($order);
        $internalEdges = $edges->filter(
            fn ($e) => isset($subtreeIds[$e->parent_id]) && isset($subtreeIds[$e->child_id])
        );

        $originals = Node::whereIn('id', $order)->get()->keyBy('id');
        $sourceRootModel = $originals[$sourceRoot->id];

        return DB::transaction(function () use (
            $space,
            $newParent,
            $order,
            $originals,
            $sourceRootModel,
            $relativeDepth,
            $internalEdges,
        ) {
            // Блокируем нового родителя на время чтения числа детей — та же
            // гонка позиций, что и в addChild(): без лока два параллельных
            // copy() под один родитель читают одинаковый count().
            $lockedParent = $newParent
                ? Node::where('id', $newParent->id)->lockForUpdate()->firstOrFail()
                : null;

            $newRootPos = $lockedParent
                ? LayoutEngine::placeChild($lockedParent, $lockedParent->childEdges()->count())
                : [
                    'x' => $sourceRootModel->pos_x + 120,
                    'y' => $sourceRootModel->pos_y + 120,
                ];

            $deltaX = $newRootPos['x'] - $sourceRootModel->pos_x;
            $deltaY = $newRootPos['y'] - $sourceRootModel->pos_y;

            $newParent = $lockedParent;
            $idMap = [];
            $newRootId = null;

            foreach ($order as $originalId) {
                $original = $originals[$originalId];
                $depth = $newParent
                    ? $newParent->depth + 1 + $relativeDepth[$originalId]
                    : $relativeDepth[$originalId];

                $clone = Node::create([
                    'space_id' => $space->id,
                    'title' => $original->title,
                    'description' => $original->description,
                    'color' => $original->color,
                    'tags' => $original->tags,
                    'map_lat' => $original->map_lat,
                    'map_lon' => $original->map_lon,
                    'map_title' => $original->map_title,
                    'pos_x' => $original->pos_x + $deltaX,
                    'pos_y' => $original->pos_y + $deltaY,
                    'pos_z' => $original->pos_z,
                    'depth' => $depth,
                ]);

                $idMap[$originalId] = $clone->id;
                $newRootId ??= $clone->id;
            }

            $rootTreeId = $newParent ? ($newParent->tree_root_id ?? $newParent->id) : $newRootId;
            Node::whereIn('id', array_values($idMap))->update(['tree_root_id' => $rootTreeId]);

            // title/description/tags копируются как есть — значит и embedding
            // источника ещё актуален для копии, звать Ollama заново незачем.
            // Копируем прямо в БД (сервер сам читает vector-колонку и
            // пишет её же клону) — не через PHP, у Eloquent нет типа для vector.
            foreach ($idMap as $originalId => $cloneId) {
                DB::statement(
                    'UPDATE nodes SET embedding = (SELECT embedding FROM nodes WHERE id = ?) WHERE id = ?',
                    [$originalId, $cloneId],
                );
            }

            foreach ($internalEdges as $edge) {
                Edge::create([
                    'space_id' => $space->id,
                    'parent_id' => $idMap[$edge->parent_id],
                    'child_id' => $idMap[$edge->child_id],
                ]);
            }

            if ($newParent) {
                Edge::create([
                    'space_id' => $space->id,
                    'parent_id' => $newParent->id,
                    'child_id' => $newRootId,
                ]);
            }

            $this->cloneAttachments($idMap);

            return Node::findOrFail($newRootId);
        });
    }

    /**
     * @param  array<int, int>  $idMap  Original node id => cloned node id.
     */
    private function cloneAttachments(array $idMap): void
    {
        $disk = Storage::disk(NodeAttachment::DISK);

        $attachments = NodeAttachment::whereIn('node_id', array_keys($idMap))
            ->orderBy('position')
            ->get();

        foreach ($attachments as $attachment) {
            $newNodeId = $idMap[$attachment->node_id];
            $newPath = null;

            if ($attachment->stored && $disk->exists($attachment->path)) {
                $newPath = 'attachments/'.$newNodeId.'/'.basename($attachment->path);
                $disk->copy($attachment->path, $newPath);
            }

            NodeAttachment::create([
                'node_id' => $newNodeId,
                'kind' => $attachment->kind,
                'label' => $attachment->label,
                'url' => $attachment->url,
                'path' => $newPath,
                'size' => $attachment->size,
                'format' => $attachment->format,
                'position' => $attachment->position,
            ]);
        }
    }
}
