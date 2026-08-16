<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\Space;
use App\Services\EmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    public function __construct(
        protected EmbeddingService $embeddings,
    ) {}

    /**
     * Те же охват и минимальная длина запроса, что и у index() — оба
     * зовутся параллельно с одного инпута (см. GlobalSearchModal.vue).
     *
     * @return Collection<int, int>
     */
    private function accessibleSpaceIds(Request $request): Collection
    {
        $user = $request->user();

        // root видит всё, включая Admin — это и есть его реальный охват
        // доступа (см. SpacePolicy::access). Остальные — своё плюс расшаренное.
        return $user->is_root
            ? Space::pluck('id')
            : Space::where('user_id', $user->id)
                ->orWhereHas('collaborators', fn ($q) => $q->where('user_id', $user->id))
                ->pluck('id');
    }

    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return response()->json(['results' => []]);
        }

        $spaceIds = $this->accessibleSpaceIds($request);

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

    /**
     * Поиск по смыслу — узлы, чей embedding ближе всего к embedding'у
     * запроса (косинусная дистанция pgvector, оператор <=>), не по
     * буквальному вхождению подстроки. Находит то, что не совпадает
     * словами, но совпадает смыслом — GlobalSearchModal.vue зовёт этот
     * эндпоинт параллельно с index(), не вместо него.
     */
    public function semantic(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return response()->json(['results' => []]);
        }

        $queryEmbedding = $this->embeddings->embed($query);

        // Ollama недоступен — деградируем до пустого результата, не до
        // ошибки: обычный (LIKE) поиск рядом продолжает работать как ни в чём не бывало.
        if ($queryEmbedding === null) {
            return response()->json(['results' => []]);
        }

        $spaceIds = $this->accessibleSpaceIds($request);
        $literal = $this->embeddings->toVectorLiteral($queryEmbedding);
        $threshold = (float) config('ollama.search_distance_threshold');

        $rows = DB::table('nodes')
            ->whereIn('space_id', $spaceIds)
            ->whereNotNull('embedding')
            ->selectRaw('id, space_id, title, description, depth, embedding <=> ?::vector AS distance', [$literal])
            ->orderBy('distance')
            ->limit(self::MAX_RESULTS)
            ->get()
            ->filter(fn ($row) => $row->distance < $threshold)
            ->values();

        $spaces = Space::whereIn('id', $rows->pluck('space_id')->unique())
            ->get(['id', 'name', 'slug'])
            ->keyBy('id');

        $results = $rows->map(fn ($row) => [
            'id' => $row->id,
            'space_id' => $row->space_id,
            'title' => $row->title,
            'description' => $row->description,
            'depth' => $row->depth,
            'space' => $spaces->get($row->space_id),
        ]);

        return response()->json(['results' => $results]);
    }
}
