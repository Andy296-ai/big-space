<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\Space;
use App\Services\LinkSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Список — под can:edit,space (те же права, что у самого связывания через
 * GraphController::link()/unlink() — подсказки полезны только тому, кто
 * может ими воспользоваться). "Принять" отдельного эндпоинта не имеет —
 * фронт зовёт существующий POST /links напрямую с предложенной парой,
 * получая всю структурную валидацию GraphRepository::validateLink() бесплатно.
 */
class NodeLinkSuggestionController extends Controller
{
    public function __construct(
        protected LinkSuggestionService $suggestions,
    ) {}

    public function index(Request $request, Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        return response()->json($this->suggestions->suggestionsFor($node));
    }

    public function dismiss(Request $request, Space $space, Node $node, Node $other): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);
        abort_unless($other->space_id === $space->id, 404);

        $this->suggestions->dismiss($node, $other, $request->user());

        return response()->json(['message' => 'ok']);
    }
}
