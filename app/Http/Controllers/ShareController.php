<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\Share;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Управление публичными read-only ссылками — владелец/root (can:manage,space,
 * см. routes/web.php). Одна активная ссылка на область (всё пространство или
 * конкретное поддерево) — не список: POST на существующую область молча
 * заменяет её новым токеном, что и есть "регенерация" из плана.
 */
class ShareController extends Controller
{
    public function showForSpace(Space $space): JsonResponse
    {
        return $this->show($space, null);
    }

    public function storeForSpace(Request $request, Space $space): JsonResponse
    {
        return $this->store($request, $space, null);
    }

    public function destroyForSpace(Space $space): JsonResponse
    {
        return $this->destroy($space, null);
    }

    public function showForNode(Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        return $this->show($space, $node);
    }

    public function storeForNode(Request $request, Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        return $this->store($request, $space, $node);
    }

    public function destroyForNode(Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        return $this->destroy($space, $node);
    }

    private function show(Space $space, ?Node $node): JsonResponse
    {
        $share = $this->findShare($space, $node);

        return response()->json($share ? $this->present($share) : null);
    }

    private function store(Request $request, Space $space, ?Node $node): JsonResponse
    {
        $this->findShare($space, $node)?->delete();

        $share = Share::create([
            'space_id' => $space->id,
            'node_id' => $node?->id,
            'token' => Str::random(40),
            'created_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        return response()->json($this->present($share), 201);
    }

    private function destroy(Space $space, ?Node $node): JsonResponse
    {
        $this->findShare($space, $node)?->delete();

        return response()->json(['message' => 'ok']);
    }

    private function findShare(Space $space, ?Node $node): ?Share
    {
        return Share::where('space_id', $space->id)->where('node_id', $node?->id)->first();
    }

    /** @return array<string, string> */
    private function present(Share $share): array
    {
        return ['token' => $share->token, 'url' => url("/shared/{$share->token}")];
    }
}
