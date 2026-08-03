<?php

namespace App\Http\Controllers;

use App\Models\Edge;
use App\Models\Node;
use App\Models\Space;
use App\Services\GraphRepository;
use App\Services\LayoutEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GraphController extends Controller
{
    public function __construct(
        protected GraphRepository $graphRepo
    ) {}

    /** Снимок для отмены удаления привязан к пространству, чтобы токен нельзя было применить к чужому. */
    private static function undoCacheKey(Space $space, string $token): string
    {
        return "undo:{$space->id}:{$token}";
    }

    public function index(Request $request): Response
    {
        // Ensure at least one space exists
        $currentSpace = null;
        if ($request->has('space')) {
            $currentSpace = Space::where('slug', $request->query('space'))->first();
        }
        if (! $currentSpace) {
            $currentSpace = Space::first();
        }

        if (! $currentSpace) {
            $currentSpace = Space::create([
                'name' => 'Default Space',
                'slug' => 'default-space',
                'description' => 'Your default graph space.',
            ]);

            $root = Node::create([
                'space_id' => $currentSpace->id,
                'title' => 'Origin',
                'description' => 'The first node in your space.',
                'pos_x' => 0,
                'pos_y' => 0,
                'pos_z' => 0,
                'depth' => 0,
                'color' => '#3b82f6',
                'tags' => 'origin,root',
            ]);
            $root->update(['tree_root_id' => $root->id]);
        }

        $allSpaces = Space::withCount(['nodes', 'edges'])->get();

        return Inertia::render('Welcome', [
            'currentSpace' => $currentSpace,
            'spaces' => $allSpaces,
        ]);
    }

    public function fetchGraph(Space $space): JsonResponse
    {
        $nodes = Node::where('space_id', $space->id)->with('attachments')->get();
        $edges = Edge::where('space_id', $space->id)->get();

        return response()->json([
            'nodes' => $nodes,
            'edges' => $edges,
        ]);
    }

    public function createRoot(Request $request, Space $space): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string',
            'tags' => 'nullable|string',
            'map_lat' => 'nullable|numeric|between:-90,90',
            'map_lon' => 'nullable|numeric|between:-180,180',
            'map_title' => 'nullable|string|max:255',
            'pos_x' => 'nullable|numeric',
            'pos_y' => 'nullable|numeric',
            'pos_z' => 'nullable|numeric',
        ]);

        $node = Node::create([
            'space_id' => $space->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'color' => $validated['color'] ?? '',
            'tags' => $validated['tags'] ?? '',
            'map_lat' => $validated['map_lat'] ?? null,
            'map_lon' => $validated['map_lon'] ?? null,
            'map_title' => $validated['map_title'] ?? null,
            'pos_x' => $validated['pos_x'] ?? 0,
            'pos_y' => $validated['pos_y'] ?? 0,
            'pos_z' => $validated['pos_z'] ?? 0,
            'depth' => 0,
        ]);
        $node->update(['tree_root_id' => $node->id]);

        return response()->json($node, 201);
    }

    public function addChild(Request $request, Space $space, Node $parent): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string',
            'tags' => 'nullable|string',
            'map_lat' => 'nullable|numeric|between:-90,90',
            'map_lon' => 'nullable|numeric|between:-180,180',
            'map_title' => 'nullable|string|max:255',
        ]);

        $childCount = $parent->childEdges()->count();
        $pos = LayoutEngine::placeChild($parent, $childCount);

        $child = Node::create([
            'space_id' => $space->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'color' => $validated['color'] ?? '',
            'tags' => $validated['tags'] ?? '',
            'map_lat' => $validated['map_lat'] ?? null,
            'map_lon' => $validated['map_lon'] ?? null,
            'map_title' => $validated['map_title'] ?? null,
            'pos_x' => $pos['x'],
            'pos_y' => $pos['y'],
            'pos_z' => $pos['z'],
            'depth' => $parent->depth + 1,
            'tree_root_id' => $parent->tree_root_id ?? $parent->id,
        ]);

        Edge::create([
            'space_id' => $space->id,
            'parent_id' => $parent->id,
            'child_id' => $child->id,
        ]);

        return response()->json([
            'node' => $child,
            'edge' => Edge::where('parent_id', $parent->id)->where('child_id', $child->id)->first(),
        ], 201);
    }

    public function move(Request $request, Space $space, Node $node): JsonResponse
    {
        $validated = $request->validate([
            'pos_x' => 'required|numeric',
            'pos_y' => 'required|numeric',
            'pos_z' => 'nullable|numeric',
        ]);

        $node->update([
            'pos_x' => $validated['pos_x'],
            'pos_y' => $validated['pos_y'],
            'pos_z' => $validated['pos_z'] ?? $node->pos_z,
        ]);

        return response()->json($node);
    }

    public function bulkMove(Request $request, Space $space): JsonResponse
    {
        $validated = $request->validate([
            'positions' => 'required|array',
            'positions.*.id' => 'required|exists:nodes,id',
            'positions.*.pos_x' => 'required|numeric',
            'positions.*.pos_y' => 'required|numeric',
        ]);

        foreach ($validated['positions'] as $item) {
            Node::where('id', $item['id'])->where('space_id', $space->id)->update([
                'pos_x' => $item['pos_x'],
                'pos_y' => $item['pos_y'],
            ]);
        }

        return response()->json(['message' => 'Node positions updated successfully']);
    }

    public function update(Request $request, Space $space, Node $node): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string',
            'tags' => 'nullable|string',
            'map_lat' => 'nullable|numeric|between:-90,90',
            'map_lon' => 'nullable|numeric|between:-180,180',
            'map_title' => 'nullable|string|max:255',
            'pos_x' => 'nullable|numeric',
            'pos_y' => 'nullable|numeric',
        ]);

        $node->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'color' => $validated['color'] ?? '',
            'tags' => $validated['tags'] ?? '',
            'map_lat' => $validated['map_lat'] ?? null,
            'map_lon' => $validated['map_lon'] ?? null,
            'map_title' => $validated['map_title'] ?? null,
            // Координаты редактируются вручную только у корневых узлов — у
            // остальных при отсутствии значения оставляем как есть.
            'pos_x' => $validated['pos_x'] ?? $node->pos_x,
            'pos_y' => $validated['pos_y'] ?? $node->pos_y,
        ]);

        return response()->json($node->load('attachments'));
    }

    public function link(Request $request, Space $space): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => 'required|exists:nodes,id',
            'child_id' => 'required|exists:nodes,id',
        ]);

        $violation = $this->graphRepo->validateLink($space, $validated['parent_id'], $validated['child_id']);

        if ($violation !== null) {
            return response()->json([
                'reason' => $violation,
                'error' => match ($violation) {
                    'self_link' => 'Cannot link a node to itself.',
                    'single_parent' => 'This space is a strict tree: the target node already has a parent.',
                    default => 'Cannot create link: adding this connection would create a cycle.',
                },
            ], 422);
        }

        // В уровневой структуре ребёнок должен оказаться ровно на уровень ниже:
        // если это не так, пробуем сдвинуть его поддерево целиком.
        $depthChanges = [];

        if ($space->requiresAdjacentLevels()) {
            $depthChanges = $this->graphRepo->planLevelShift($space, $validated['parent_id'], $validated['child_id']);

            if ($depthChanges === null) {
                return response()->json([
                    'reason' => 'level_gap',
                    'error' => 'This space is leveled: the link would span more than one level.',
                ], 422);
            }
        }

        $edge = DB::transaction(function () use ($space, $validated, $depthChanges) {
            $this->graphRepo->applyDepths($space->id, $depthChanges);

            return Edge::firstOrCreate([
                'space_id' => $space->id,
                'parent_id' => $validated['parent_id'],
                'child_id' => $validated['child_id'],
            ]);
        });

        $this->graphRepo->updateTreeRootIds($space->id);

        return response()->json($edge, 201);
    }

    public function unlink(Request $request, Space $space): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => 'required|exists:nodes,id',
            'child_id' => 'required|exists:nodes,id',
        ]);

        Edge::where('space_id', $space->id)
            ->where('parent_id', $validated['parent_id'])
            ->where('child_id', $validated['child_id'])
            ->delete();

        $this->graphRepo->updateTreeRootIds($space->id);

        return response()->json(['message' => 'Link removed successfully']);
    }

    public function computeDeletion(Space $space, Node $node): JsonResponse
    {
        $deletionIds = $this->graphRepo->computeDeletionSetForSpace($space, [$node->id]);

        return response()->json([
            'target_id' => $node->id,
            'deletion_ids' => $deletionIds,
            'count' => count($deletionIds),
        ]);
    }

    public function deleteNodes(Request $request, Space $space): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        // Список от клиента — это намерение, а не готовое решение: берём только
        // узлы этого пространства и пересчитываем каскад на сервере.
        $requestedIds = Node::where('space_id', $space->id)
            ->whereIn('id', $validated['ids'])
            ->pluck('id')
            ->all();

        if (empty($requestedIds)) {
            return response()->json(['error' => 'No matching nodes in this space.'], 422);
        }

        $deletionIds = $this->graphRepo->computeDeletionSetForSpace($space, $requestedIds);

        $nodesBackup = Node::whereIn('id', $deletionIds)->get();
        $edgesBackup = Edge::where('space_id', $space->id)
            ->where(function ($query) use ($deletionIds) {
                $query->whereIn('parent_id', $deletionIds)
                    ->orWhereIn('child_id', $deletionIds);
            })
            ->get();

        DB::transaction(function () use ($space, $deletionIds) {
            Edge::where('space_id', $space->id)
                ->where(function ($query) use ($deletionIds) {
                    $query->whereIn('parent_id', $deletionIds)
                        ->orWhereIn('child_id', $deletionIds);
                })
                ->delete();

            Node::where('space_id', $space->id)->whereIn('id', $deletionIds)->delete();
        });

        $this->graphRepo->updateTreeRootIds($space->id);

        // Снимок остаётся на сервере, клиенту достаётся только одноразовый токен.
        // Раньше тело restore приходило от клиента, то есть через него можно было
        // вставить в базу произвольные строки с произвольными id.
        $token = (string) Str::uuid();

        Cache::put(
            self::undoCacheKey($space, $token),
            ['nodes' => $nodesBackup->toArray(), 'edges' => $edgesBackup->toArray()],
            now()->addHour(),
        );

        return response()->json([
            'message' => 'Nodes deleted successfully',
            'undo_token' => $token,
            'deleted_ids' => $deletionIds,
        ]);
    }

    public function restoreNodes(Request $request, Space $space): JsonResponse
    {
        $validated = $request->validate([
            'undo_token' => 'required|string',
        ]);

        $backup = Cache::pull(self::undoCacheKey($space, $validated['undo_token']));

        if ($backup === null) {
            return response()->json([
                'reason' => 'undo_expired',
                'error' => 'Nothing to restore: the undo snapshot is gone.',
            ], 410);
        }

        DB::transaction(function () use ($backup, $space) {
            foreach ($backup['nodes'] as $node) {
                DB::table('nodes')->insertOrIgnore([
                    'id' => $node['id'],
                    'space_id' => $space->id,
                    'title' => $node['title'],
                    'description' => $node['description'],
                    'pos_x' => $node['pos_x'],
                    'pos_y' => $node['pos_y'],
                    'pos_z' => $node['pos_z'],
                    'depth' => $node['depth'],
                    'color' => $node['color'],
                    'tags' => $node['tags'],
                    'tree_root_id' => null,
                    'created_at' => $node['created_at'],
                    'updated_at' => $node['updated_at'],
                ]);
            }

            foreach ($backup['edges'] as $edge) {
                DB::table('edges')->insertOrIgnore([
                    'id' => $edge['id'],
                    'space_id' => $space->id,
                    'parent_id' => $edge['parent_id'],
                    'child_id' => $edge['child_id'],
                    'created_at' => $edge['created_at'],
                    'updated_at' => $edge['updated_at'],
                ]);
            }
        });

        $this->graphRepo->updateTreeRootIds($space->id);

        return response()->json(['message' => 'Nodes and edges restored successfully']);
    }
}
