<?php

namespace App\Http\Controllers;

use App\Events\SpaceUpdated;
use App\Models\ActivityLog;
use App\Models\Edge;
use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\NodeRevision;
use App\Models\Space;
use App\Models\User;
use App\Services\GraphRepository;
use App\Services\LayoutEngine;
use App\Services\SpaceProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function index(Request $request, SpaceProvisioner $provisioner): Response
    {
        $user = $request->user();
        $currentSpace = null;

        // Явно запрошенный slug честно проверяем правами — так root может
        // открыть чужое пространство по ссылке из Admin-графа, а остальные нет.
        if ($request->has('space')) {
            $bySlug = Space::where('slug', $request->query('space'))->first();

            if ($bySlug && Gate::forUser($user)->allows('access', $bySlug)) {
                $currentSpace = $bySlug;
            }
        }

        // Admin — служебное пространство, обычной "рабочей" точкой входа быть не должно.
        $currentSpace ??= Space::where('user_id', $user->id)->where('is_admin', false)->first();
        $currentSpace ??= $provisioner->createForUser($user, 'Default Space', 'Your default graph space.');
        $currentSpace->role = $currentSpace->roleFor($user);

        $owned = Space::withCount(['nodes', 'edges'])->where('user_id', $user->id)->get()
            ->each(fn (Space $s) => $s->role = 'owner');
        $shared = $user->sharedSpaces()->withCount(['nodes', 'edges'])->with('user:id,name')->get()
            ->each(function (Space $s) {
                $s->role = $s->pivot->role;
                $s->owner_name = $s->user->name;
            });

        // Переход из глобального поиска: узел должен реально быть в этом
        // пространстве, иначе просто ничего не подсвечиваем.
        $focusNodeId = null;

        if ($request->filled('focus')) {
            $focusNodeId = Node::where('id', $request->query('focus'))
                ->where('space_id', $currentSpace->id)
                ->value('id');
        }

        return Inertia::render('Welcome', [
            'currentSpace' => $currentSpace,
            'spaces' => $owned->concat($shared)->values(),
            'focusNodeId' => $focusNodeId,
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
            'shape' => ['nullable', Rule::in(Node::SHAPES)],
        ]);

        $node = Node::create([
            'space_id' => $space->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'color' => $validated['color'] ?? '',
            'tags' => $validated['tags'] ?? '',
            'shape' => $validated['shape'] ?? 'circle',
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
            'shape' => ['nullable', Rule::in(Node::SHAPES)],
        ]);

        $childCount = $parent->childEdges()->count();
        $pos = LayoutEngine::placeChild($parent, $childCount);

        $child = Node::create([
            'space_id' => $space->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'color' => $validated['color'] ?? '',
            'tags' => $validated['tags'] ?? '',
            'shape' => $validated['shape'] ?? 'circle',
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

    /** Клонирует узел вместе со всем поддеревом и вложениями под указанным родителем (или новым корнем, если parent_id не задан). */
    public function copy(Request $request, Space $space, Node $node): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|integer',
        ]);

        $newParent = null;

        if (! empty($validated['parent_id'])) {
            $newParent = Node::where('space_id', $space->id)
                ->where('id', $validated['parent_id'])
                ->first();

            if (! $newParent) {
                return response()->json(['error' => 'Parent node not found in this space.'], 422);
            }
        }

        $copy = $this->graphRepo->copySubtree($space, $node, $newParent);

        return response()->json($copy->load('attachments'), 201);
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

        // Query-builder update() не будит модельные события — сигналим о живом обновлении сами.
        SpaceUpdated::dispatch($space->id);

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
            'shape' => ['nullable', Rule::in(Node::SHAPES)],
        ]);

        // Снимок состояния ДО правки — чтобы её можно было откатить одной кнопкой.
        NodeRevision::snapshot($node, $request->user());

        $node->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'color' => $validated['color'] ?? '',
            'tags' => $validated['tags'] ?? '',
            'shape' => $validated['shape'] ?? $node->shape,
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

    /** История правок узла, новые сверху. */
    public function nodeRevisions(Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        $revisions = $node->revisions()
            ->with('editor:id,name')
            ->latest('created_at')
            ->get();

        return response()->json($revisions);
    }

    /**
     * Откатывает узел к состоянию из снимка. Перед этим снимает ЕЩЁ один
     * снимок — текущего (пока ещё не откаченного) состояния, поэтому сам
     * откат тоже можно откатить через ту же историю.
     */
    public function restoreNodeRevision(Request $request, Space $space, Node $node, NodeRevision $revision): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);
        abort_unless($revision->node_id === $node->id, 404);

        NodeRevision::snapshot($node, $request->user());

        $node->update([
            'title' => $revision->title,
            'description' => $revision->description ?? '',
            'color' => $revision->color ?? '',
            'tags' => $revision->tags ?? '',
            'shape' => $revision->shape ?? $node->shape,
            'map_lat' => $revision->map_lat,
            'map_lon' => $revision->map_lon,
            'map_title' => $revision->map_title,
            'pos_x' => $revision->pos_x ?? $node->pos_x,
            'pos_y' => $revision->pos_y ?? $node->pos_y,
        ]);

        return response()->json($node->load('attachments'));
    }

    /** Отдаёт логотип узла — как и вложения, лежит в приватном хранилище. */
    public function logo(Space $space, Node $node): StreamedResponse
    {
        abort_unless($node->space_id === $space->id, 404);
        abort_if($node->logo_path === null, 404);

        $disk = Storage::disk(NodeAttachment::DISK);
        abort_unless($disk->exists($node->logo_path), 404);

        return $disk->response($node->logo_path);
    }

    public function uploadLogo(Request $request, Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        $request->validate([
            'logo' => 'required|image|max:5120',
        ]);

        $disk = Storage::disk(NodeAttachment::DISK);

        if ($node->logo_path !== null) {
            $disk->delete($node->logo_path);
        }

        $path = $request->file('logo')->store("logos/{$node->id}", NodeAttachment::DISK);
        $node->update(['logo_path' => $path]);

        return response()->json($node);
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
        SpaceUpdated::dispatch($space->id);

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

        // Некоторые из этих узлов в Admin-пространстве — зеркала реальных
        // пользователей/пространств (см. SpaceProvisioner). Удаление узла
        // отсюда должно унести с собой и то, что он представляет — иначе
        // запись осталась бы висеть без узла, который ею управляет.
        $deletionNodes = Node::whereIn('id', $deletionIds)->get(['id', 'linked_user_id', 'linked_space_id']);
        $linkedUsers = User::whereIn('id', $deletionNodes->pluck('linked_user_id')->filter()->unique())->get();

        if ($linkedUsers->contains('is_root', true)) {
            return response()->json(['error' => 'The root user cannot be deleted.'], 422);
        }

        // Пространства, чей владелец удаляется этим же запросом, не трогаем —
        // они просто останутся бесхозными, как и при прямом удалении
        // пользователя (см. Admin\UserController::destroy). «По-настоящему»
        // удаляем только те, чей узел стёрли отдельно от владельца.
        $linkedSpaceIds = $deletionNodes->pluck('linked_space_id')->filter()->unique();
        $linkedSpaces = Space::whereIn('id', $linkedSpaceIds)
            ->whereNotIn('user_id', $linkedUsers->pluck('id'))
            ->get();

        foreach ($linkedSpaces as $linkedSpace) {
            if (Space::where('user_id', $linkedSpace->user_id)->count() <= 1) {
                return response()->json(['error' => "Cannot delete node: \"{$linkedSpace->name}\" is its owner's only space."], 422);
            }
        }

        [$nodesBackup, $edgesBackup] = DB::transaction(function () use ($space, $deletionIds, $linkedSpaces, $linkedUsers) {
            // Сначала удаляем то, что узлы представляют — благодаря
            // nullOnDelete() на linked_space_id/linked_user_id это обнулит
            // ссылки на узлах ещё до того, как мы их сохраним для отмены.
            // Иначе после restore узел ожил бы со ссылкой на уже несуществующую запись.
            Space::whereIn('id', $linkedSpaces->pluck('id'))->delete();
            User::whereIn('id', $linkedUsers->pluck('id'))->delete();

            $nodesBackup = Node::whereIn('id', $deletionIds)->get();
            $edgesBackup = Edge::where('space_id', $space->id)
                ->where(function ($query) use ($deletionIds) {
                    $query->whereIn('parent_id', $deletionIds)
                        ->orWhereIn('child_id', $deletionIds);
                })
                ->get();

            Edge::where('space_id', $space->id)
                ->where(function ($query) use ($deletionIds) {
                    $query->whereIn('parent_id', $deletionIds)
                        ->orWhereIn('child_id', $deletionIds);
                })
                ->delete();

            Node::where('space_id', $space->id)->whereIn('id', $deletionIds)->delete();

            return [$nodesBackup, $edgesBackup];
        });

        $this->graphRepo->updateTreeRootIds($space->id);
        SpaceUpdated::dispatch($space->id);

        foreach ($linkedSpaces as $linkedSpace) {
            ActivityLog::record(
                $request->user(),
                ActivityLog::ACTION_SPACE_DELETED,
                'space',
                $linkedSpace->id,
                ['name' => $linkedSpace->name, 'is_own' => $linkedSpace->user_id === $request->user()->id],
            );
        }

        foreach ($linkedUsers as $linkedUser) {
            ActivityLog::record(
                $request->user(),
                ActivityLog::ACTION_USER_DELETED,
                'user',
                $linkedUser->id,
                ['name' => $linkedUser->name, 'email' => $linkedUser->email],
            );
        }

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
        SpaceUpdated::dispatch($space->id);

        return response()->json(['message' => 'Nodes and edges restored successfully']);
    }
}
