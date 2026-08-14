<?php

namespace App\Http\Controllers;

use App\Events\SpaceUpdated;
use App\Models\ActivityLog;
use App\Models\Edge;
use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Space;
use App\Services\GraphRepository;
use App\Services\SpaceProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SpaceController extends Controller
{
    /** Метка формата: по ней импорт отличает свой файл от чужого. */
    public const EXPORT_FORMAT = 'infinite-space/v1';

    /** С запасом сверх любого реалистичного пространства — не лимит на использование, а потолок против случайно/намеренно огромного файла. */
    private const MAX_IMPORT_NODES = 5000;

    private const MAX_IMPORT_EDGES = 20000;

    /** Свои пространства плюс те, что расшарили пользователю — чужое без шеринга (и Admin) сюда не попадает. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $owned = Space::withCount(['nodes', 'edges'])
            ->where('user_id', $user->id)
            ->get()
            ->each(fn (Space $space) => $space->role = 'owner');

        $shared = $user->sharedSpaces()
            ->withCount(['nodes', 'edges'])
            ->with('user:id,name')
            ->get()
            ->each(function (Space $space) {
                $space->role = $space->pivot->role;
                $space->owner_name = $space->user->name;
            });

        return response()->json($owned->concat($shared)->values());
    }

    public function store(Request $request, SpaceProvisioner $provisioner): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'structure' => ['nullable', Rule::in(Space::STRUCTURES)],
        ]);

        $space = $provisioner->createForUser(
            $request->user(),
            $validated['name'],
            $validated['description'] ?? '',
            $validated['structure'] ?? Space::STRUCTURE_DAG,
        );

        return response()->json($space->loadCount(['nodes', 'edges']), 201);
    }

    /**
     * Switches the space to another structure, refusing when the existing graph
     * would violate the stricter rules.
     */
    public function updateStructure(Request $request, Space $space, GraphRepository $graphRepo): JsonResponse
    {
        $validated = $request->validate([
            'structure' => ['required', Rule::in(Space::STRUCTURES)],
        ]);

        $conflict = $graphRepo->structureConflicts($space, $validated['structure']);

        if ($conflict !== null) {
            return response()->json([
                'reason' => $conflict['reason'],
                'conflicts' => $conflict['conflicts'],
                'error' => match ($conflict['reason']) {
                    'single_parent' => "Cannot switch to a strict tree: {$conflict['conflicts']} node(s) have more than one parent.",
                    'level_gap' => "Cannot switch to a leveled graph: {$conflict['conflicts']} link(s) span more than one level.",
                    default => 'Cannot switch: the graph contains a cycle.',
                },
            ], 422);
        }

        // Уровневая структура опирается на depth, поэтому фиксируем глубины,
        // на которых проверка только что сошлась.
        if ($validated['structure'] === Space::STRUCTURE_LEVELED) {
            $graphRepo->applyDepths($space->id, $graphRepo->computeLevelDepths($space->id));
        }

        $space->update(['structure' => $validated['structure']]);
        SpaceUpdated::dispatch($space->id);

        ActivityLog::record(
            $request->user(),
            ActivityLog::ACTION_STRUCTURE_CHANGED,
            'space',
            $space->id,
            ['action' => 'mode_changed', 'structure' => $validated['structure']],
            $space->id,
        );

        return response()->json($space->loadCount(['nodes', 'edges']));
    }

    /**
     * Выгружает пространство целиком. Узлы нумеруются ключами внутри файла,
     * а не id из базы — иначе импорт в другую базу пришлось бы подгонять.
     */
    public function export(Request $request, Space $space): JsonResponse
    {
        ActivityLog::record(
            $request->user(),
            ActivityLog::ACTION_SPACE_EXPORTED,
            'space',
            $space->id,
            [],
            $space->id,
        );

        $nodes = Node::where('space_id', $space->id)
            ->with('attachments')
            ->orderBy('id')
            ->get();
        $keyOf = $nodes->pluck('id')->flip()->map(fn ($i) => $i + 1);

        $payload = [
            'format' => self::EXPORT_FORMAT,
            'exported_at' => now()->toIso8601String(),
            'space' => [
                'name' => $space->name,
                'description' => $space->description ?? '',
                'structure' => $space->structure,
            ],
            'nodes' => $nodes->map(fn (Node $n) => [
                'key' => $keyOf[$n->id],
                'title' => $n->title,
                'description' => $n->description,
                'pos_x' => $n->pos_x,
                'pos_y' => $n->pos_y,
                'depth' => $n->depth,
                'color' => $n->color,
                'tags' => $n->tags,
                'map_lat' => $n->map_lat,
                'map_lon' => $n->map_lon,
                'map_title' => $n->map_title,
                // Выгружаем только ссылки: у загруженного файла адреса нет,
                // а содержимое в JSON не поместить — такой файл не пережил бы
                // обратный импорт. Файлы остаются в хранилище пространства.
                'attachments' => $n->attachments
                    ->reject(fn (NodeAttachment $a) => $a->stored)
                    ->map(fn (NodeAttachment $a) => [
                        'kind' => $a->kind,
                        'label' => $a->label,
                        'url' => (string) $a->url,
                        'format' => $a->format,
                    ])
                    ->values(),
            ])->values(),
            'edges' => Edge::where('space_id', $space->id)
                ->orderBy('id')
                ->get()
                ->map(fn (Edge $e) => [
                    'parent' => $keyOf[$e->parent_id],
                    'child' => $keyOf[$e->child_id],
                ])
                ->values(),
        ];

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$space->slug.'.json"',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Создаёт новое пространство из выгрузки. Ключи из файла переводятся в
     * свежие id, поэтому файл не может ничего перезаписать в базе.
     */
    public function import(Request $request, GraphRepository $graphRepo, SpaceProvisioner $provisioner): JsonResponse
    {
        // Проверяем размер ДО полной валидации: Laravel разворачивает
        // wildcard-правила (nodes.*.key и т.п.) по каждому элементу массива
        // независимо от других правил, так что 'nodes' => 'max:...' само по
        // себе не спасает — на тысячах элементов сама валидация успевает
        // упереться в max_execution_time раньше, чем сработает max. Считаем
        // count() вручную, до вызова validate().
        if (count((array) $request->input('nodes', [])) > self::MAX_IMPORT_NODES) {
            throw ValidationException::withMessages(['nodes' => 'Too many nodes in the import file.']);
        }

        if (count((array) $request->input('edges', [])) > self::MAX_IMPORT_EDGES) {
            throw ValidationException::withMessages(['edges' => 'Too many edges in the import file.']);
        }

        $validated = $request->validate([
            'format' => ['required', Rule::in([self::EXPORT_FORMAT])],
            'space.name' => 'required|string|max:255',
            'space.description' => 'nullable|string',
            'space.structure' => ['nullable', Rule::in(Space::STRUCTURES)],
            'nodes' => 'required|array|min:1|max:'.self::MAX_IMPORT_NODES,
            'nodes.*.key' => 'required|integer',
            'nodes.*.title' => 'nullable|string|max:255',
            'nodes.*.description' => 'nullable|string',
            'nodes.*.pos_x' => 'nullable|numeric',
            'nodes.*.pos_y' => 'nullable|numeric',
            'nodes.*.color' => 'nullable|string|max:32',
            'nodes.*.tags' => 'nullable|string|max:255',
            'nodes.*.map_lat' => 'nullable|numeric|between:-90,90',
            'nodes.*.map_lon' => 'nullable|numeric|between:-180,180',
            'nodes.*.map_title' => 'nullable|string|max:255',
            'nodes.*.attachments' => 'nullable|array',
            'nodes.*.attachments.*.kind' => ['nullable', Rule::in(NodeAttachment::KINDS)],
            'nodes.*.attachments.*.label' => 'nullable|string|max:255',
            'nodes.*.attachments.*.url' => 'required|string|max:2048',
            'nodes.*.attachments.*.format' => 'nullable|string|max:8',
            'edges' => 'present|array|max:'.self::MAX_IMPORT_EDGES,
            'edges.*.parent' => 'required|integer',
            'edges.*.child' => 'required|integer',
        ]);

        $keys = array_column($validated['nodes'], 'key');

        if (count($keys) !== count(array_unique($keys))) {
            return response()->json([
                'reason' => 'duplicate_keys',
                'error' => 'Node keys must be unique.',
            ], 422);
        }

        $known = array_flip($keys);

        foreach ($validated['edges'] as $edge) {
            if (! isset($known[$edge['parent']], $known[$edge['child']])) {
                return response()->json([
                    'reason' => 'unknown_edge_key',
                    'error' => 'An edge references a node key that is not in the file.',
                ], 422);
            }
        }

        $structure = $validated['space']['structure'] ?? Space::STRUCTURE_DAG;

        DB::beginTransaction();

        $space = Space::create([
            'user_id' => $request->user()->id,
            'name' => $validated['space']['name'],
            'slug' => $provisioner->uniqueSlug($validated['space']['name']),
            'description' => $validated['space']['description'] ?? '',
            'structure' => $structure,
        ]);

        $idOf = [];

        foreach ($validated['nodes'] as $node) {
            $created = Node::create([
                'space_id' => $space->id,
                'title' => $node['title'] ?? '',
                'description' => $node['description'] ?? '',
                'pos_x' => $node['pos_x'] ?? 0,
                'pos_y' => $node['pos_y'] ?? 0,
                'pos_z' => 0,
                'depth' => 0,
                'color' => $node['color'] ?? '',
                'tags' => $node['tags'] ?? '',
                'map_lat' => $node['map_lat'] ?? null,
                'map_lon' => $node['map_lon'] ?? null,
                'map_title' => $node['map_title'] ?? null,
            ]);

            foreach (array_values($node['attachments'] ?? []) as $position => $attachment) {
                $created->attachments()->create([
                    'kind' => $attachment['kind'] ?? NodeAttachment::KIND_FILE,
                    'label' => $attachment['label'] ?? '',
                    'url' => $attachment['url'],
                    'format' => $attachment['format'] ?? '',
                    'position' => $position,
                ]);
            }

            $idOf[$node['key']] = $created->id;
        }

        foreach ($validated['edges'] as $edge) {
            Edge::firstOrCreate([
                'space_id' => $space->id,
                'parent_id' => $idOf[$edge['parent']],
                'child_id' => $idOf[$edge['child']],
            ]);
        }

        // Глубины считаем сами: в файле они могли быть любыми, а от них зависят
        // фильтры, раскраска по уровню и проверка уровневой структуры.
        $graphRepo->applyDepths($space->id, $graphRepo->computeLevelDepths($space->id));

        $conflict = $graphRepo->structureConflicts($space->fresh(), $structure);

        if ($conflict !== null) {
            DB::rollBack();

            return response()->json([
                'reason' => $conflict['reason'],
                'conflicts' => $conflict['conflicts'],
                'error' => 'The imported graph does not satisfy the declared structure.',
            ], 422);
        }

        $graphRepo->updateTreeRootIds($space->id);
        $provisioner->linkIntoAdminSpace($request->user(), $space);
        DB::commit();

        return response()->json($space->fresh()->loadCount(['nodes', 'edges']), 201);
    }

    public function destroy(Request $request, Space $space, GraphRepository $graphRepo): JsonResponse
    {
        if ($space->is_admin) {
            return response()->json(['error' => 'The Admin space cannot be deleted.'], 422);
        }

        // У владельца этого пространства должно остаться хотя бы одно своё.
        if (Space::where('user_id', $space->user_id)->count() <= 1) {
            return response()->json(['error' => 'Cannot delete the only space'], 422);
        }

        $spaceId = $space->id;
        $spaceName = $space->name;
        $ownerId = $space->user_id;

        // У пространства может быть узел-зеркало в Admin (см. SpaceProvisioner)
        // — без этого он остаётся висеть, указывая на уже удалённую запись.
        $linkedNode = $space->linkedNode()->first();

        DB::transaction(function () use ($space, $linkedNode, $graphRepo) {
            $space->delete();

            if ($linkedNode !== null) {
                $deletionIds = $graphRepo->computeDeletionSetForSpace($linkedNode->space, [$linkedNode->id]);
                Node::whereIn('id', $deletionIds)->delete();
            }
        });

        if ($linkedNode !== null) {
            SpaceUpdated::dispatch($linkedNode->space_id);
        }

        // Только действия root'а — обычный пользователь, удаляющий своё же
        // пространство, это не «администрирование», а рутинная работа.
        if ($request->user()->is_root) {
            ActivityLog::record(
                $request->user(),
                ActivityLog::ACTION_SPACE_DELETED,
                'space',
                $spaceId,
                ['name' => $spaceName, 'is_own' => $ownerId === $request->user()->id],
            );
        }

        return response()->json(['message' => 'Space deleted successfully']);
    }
}
