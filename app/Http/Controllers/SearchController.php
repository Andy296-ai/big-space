<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Поиск по всем пространствам пользователя разом — в отличие от поиска
 * внутри пространства (AttachmentController::search + клиентский фильтр
 * в Welcome.vue), тут нет полного текста вложений: цена сканирования файлов
 * по всем пространствам сразу не стоит того, есть отдельный поиск на месте.
 */
class SearchController extends Controller
{
    private const MAX_RESULTS = 30;

    private const MIN_QUERY_LENGTH = 2;

    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return response()->json(['results' => []]);
        }

        $user = $request->user();

        // root видит всё, включая Admin — это и есть его реальный охват
        // доступа (см. SpacePolicy::access). Остальные — своё плюс расшаренное.
        $spaceIds = $user->is_root
            ? Space::pluck('id')
            : Space::where('user_id', $user->id)
                ->orWhereHas('collaborators', fn ($q) => $q->where('user_id', $user->id))
                ->pluck('id');

        // SQLite, в отличие от MySQL, не считает "\" экранирующим символом в
        // LIKE по умолчанию — нужно явно задавать ESCAPE, иначе "%"/"_" в
        // запросе пользователя сработают как настоящие SQL-wildcard'ы.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $query);
        $needle = '%'.$escaped.'%';

        $results = Node::whereIn('space_id', $spaceIds)
            ->where(function ($q) use ($needle) {
                $q->whereRaw('title LIKE ? ESCAPE ?', [$needle, '\\'])
                    ->orWhereRaw('description LIKE ? ESCAPE ?', [$needle, '\\'])
                    ->orWhereRaw('tags LIKE ? ESCAPE ?', [$needle, '\\']);
            })
            ->with('space:id,name,slug')
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'space_id', 'title', 'description', 'depth']);

        return response()->json(['results' => $results]);
    }
}
