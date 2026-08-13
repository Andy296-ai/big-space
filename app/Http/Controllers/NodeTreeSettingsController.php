<?php

namespace App\Http\Controllers;

use App\Events\SpaceUpdated;
use App\Models\Node;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Настройки дерева живут на его корневом узле (depth = 0): форма/цвет по
 * умолчанию для будущих дочерних узлов, и опционально — немедленное
 * применение этих значений ко всем уже существующим узлам дерева.
 */
class NodeTreeSettingsController extends Controller
{
    public function update(Request $request, Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);
        abort_unless($node->depth === 0, 422, 'Tree settings are only available on a root node.');

        $validated = $request->validate([
            'default_shape' => ['nullable', Rule::in(Node::SHAPES)],
            'default_color' => 'nullable|string|max:32',
            'apply_to_all' => 'boolean',
        ]);

        $node->update([
            'default_shape' => $validated['default_shape'] ?? null,
            'default_color' => $validated['default_color'] ?? null,
        ]);

        if ($validated['apply_to_all'] ?? false) {
            // Query-builder update() не будит модельные события — сигналим о живом обновлении сами.
            Node::where('space_id', $space->id)
                ->where('tree_root_id', $node->id)
                ->update([
                    'shape' => $node->default_shape ?? 'circle',
                    'color' => $node->default_color ?? '',
                ]);

            SpaceUpdated::dispatch($space->id);
        }

        return response()->json($node->fresh());
    }
}
