<?php

namespace App\Http\Controllers;

use App\Models\NodeType;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD пользовательских типов узла внутри пространства — can:edit,space
 * (тот же охват, что у NodeTreeSettingsController: любой editor может
 * формировать схему пространства, не только владелец). Без ActivityLog —
 * NodeTreeSettingsController::update() тоже не логирует, тот же прецедент.
 */
class NodeTypeController extends Controller
{
    public function index(Space $space): JsonResponse
    {
        return response()->json(
            NodeType::where('space_id', $space->id)->orderBy('label')->get(),
        );
    }

    public function store(Request $request, Space $space): JsonResponse
    {
        $validated = $this->validated($request, $space);

        $type = NodeType::create([...$validated, 'space_id' => $space->id]);

        return response()->json($type, 201);
    }

    public function update(Request $request, Space $space, NodeType $nodeType): JsonResponse
    {
        abort_unless($nodeType->space_id === $space->id, 404);

        $validated = $this->validated($request, $space, $nodeType);

        $nodeType->update($validated);

        return response()->json($nodeType->fresh());
    }

    public function destroy(Space $space, NodeType $nodeType): JsonResponse
    {
        abort_unless($nodeType->space_id === $space->id, 404);

        // nullOnDelete на nodes.node_type_id — узлы этого типа не удаляются,
        // просто "разтипизируются".
        $nodeType->delete();

        return response()->json(['message' => 'ok']);
    }

    /** @return array{key: string, label: string, icon: ?string, color: ?string, field_schema: ?array<int, array<string, mixed>>} */
    private function validated(Request $request, Space $space, ?NodeType $ignoring = null): array
    {
        $validated = $request->validate([
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('node_types', 'key')
                    ->where('space_id', $space->id)
                    ->ignore($ignoring),
            ],
            'label' => 'required|string|max:64',
            'icon' => 'nullable|string|max:64',
            'color' => 'nullable|string|max:32',
            'field_schema' => 'nullable|array',
            'field_schema.*.key' => ['required_with:field_schema', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'field_schema.*.label' => 'required_with:field_schema|string|max:64',
            'field_schema.*.type' => ['required_with:field_schema', Rule::in(NodeType::FIELD_KINDS)],
            'field_schema.*.required' => 'boolean',
            'field_schema.*.options' => 'nullable|array',
            'field_schema.*.options.*' => 'string|max:64',
        ]);

        $validated['field_schema'] = $this->cleanFieldSchema($validated['field_schema'] ?? []);

        return $validated;
    }

    /**
     * select/multiselect обязаны иметь непустые options; ключи полей не
     * должны повторяться внутри одной схемы; photo/files — не больше одного
     * слота на тип (у узла один логотип и один общий список вложений).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>|null
     */
    private function cleanFieldSchema(array $fields): ?array
    {
        if ($fields === []) {
            return null;
        }

        $seenKeys = [];
        $singletonCounts = array_fill_keys(NodeType::SINGLETON_FIELD_KINDS, 0);

        foreach ($fields as $field) {
            $key = $field['key'];
            $type = $field['type'];

            if (isset($seenKeys[$key])) {
                throw ValidationException::withMessages([
                    'field_schema' => "Field key \"{$key}\" is used more than once.",
                ]);
            }
            $seenKeys[$key] = true;

            if (in_array($type, ['select', 'multiselect'], true) && empty($field['options'])) {
                throw ValidationException::withMessages([
                    'field_schema' => "Field \"{$key}\" needs at least one option.",
                ]);
            }

            if (array_key_exists($type, $singletonCounts)) {
                $singletonCounts[$type]++;

                if ($singletonCounts[$type] > 1) {
                    throw ValidationException::withMessages([
                        'field_schema' => "A type can only have one \"{$type}\" field.",
                    ]);
                }
            }
        }

        return array_values($fields);
    }
}
