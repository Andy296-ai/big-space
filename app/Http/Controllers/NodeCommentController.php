<?php

namespace App\Http\Controllers;

use App\Concerns\ResolvesUserMentions;
use App\Events\CommentPosted;
use App\Events\NotificationPosted;
use App\Models\Node;
use App\Models\NodeComment;
use App\Models\Space;
use App\Models\User;
use App\Notifications\UserMentioned;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Обсуждение прямо на узле — доступно на чтение/запись всем, у кого есть
 * доступ к пространству (viewer в том числе, как в Google Docs), удаление —
 * автору или владельцу/root.
 */
class NodeCommentController extends Controller
{
    use ResolvesUserMentions;

    public function index(Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        // oldest('id') тай-брейком — см. тот же комментарий в
        // Admin\ActivityLogController::index(): несколько комментариев,
        // созданных в одной транзакции (в т.ч. в тестах), в Postgres
        // получают одинаковый created_at.
        $comments = $node->comments()
            ->with('user:id,name')
            ->oldest('created_at')
            ->oldest('id')
            ->get();

        return response()->json($this->serializeComments($comments, $space));
    }

    public function store(Request $request, Space $space, Node $node): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);

        // Триммим ДО валидации: иначе строка из одних пробелов проходит
        // required (он отвергает только null/пустую строку/пустой массив).
        $request->merge(['body' => trim((string) $request->input('body', ''))]);

        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $comment = $node->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        CommentPosted::dispatch($space->id, $node->id);
        $this->notifyMentionedUsers($comment, $space, $node, $request->user());

        return response()->json($this->serializeComment($comment->load('user:id,name'), $space->mentionableUsers()), 201);
    }

    /** Список кандидатов на @упоминание в композере комментариев этого пространства. */
    public function mentionableUsers(Space $space): JsonResponse
    {
        return response()->json(
            $space->mentionableUsers()
                ->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])
                ->values(),
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, NodeComment>  $comments
     * @return array<int, array<string, mixed>>
     */
    private function serializeComments($comments, Space $space): array
    {
        $mentionable = $space->mentionableUsers();

        return $comments->map(fn (NodeComment $c) => $this->serializeComment($c, $mentionable))->all();
    }

    /**
     * @param  Collection<int, string>  $mentionable  id => name
     * @return array<string, mixed>
     */
    private function serializeComment(NodeComment $comment, Collection $mentionable): array
    {
        $array = $comment->toArray();
        $array['user_mentions'] = $this->resolveUserMentions($comment->body, $mentionable);

        return $array;
    }

    /**
     * @упоминания людей — только при первой отправке, круг — те же
     * кандидаты, что и в автокомплите композера (Space::mentionableUsers()).
     */
    private function notifyMentionedUsers(NodeComment $comment, Space $space, Node $node, User $sender): void
    {
        $mentioned = $this->resolveUserMentions($comment->body, $space->mentionableUsers());

        foreach ($mentioned as $entry) {
            if ($entry['id'] === $sender->id) {
                continue;
            }

            $user = User::find((int) $entry['id']);

            if (! $user) {
                continue;
            }

            $user->notify(new UserMentioned(
                contextType: 'comment',
                contextId: $comment->id,
                excerpt: Str::limit($comment->body, 100),
                mentionedByName: $sender->name,
                spaceId: $space->id,
                spaceSlug: $space->slug,
                nodeId: $node->id,
            ));
            NotificationPosted::dispatch($user->id);
        }
    }

    public function destroy(Request $request, Space $space, Node $node, NodeComment $comment): JsonResponse
    {
        abort_unless($node->space_id === $space->id, 404);
        abort_unless($comment->node_id === $node->id, 404);

        $user = $request->user();
        $canModerate = $user->is_root || $space->user_id === $user->id;
        abort_unless($comment->user_id === $user->id || $canModerate, 403);

        $comment->delete();

        CommentPosted::dispatch($space->id, $node->id);

        return response()->json(['message' => 'Comment deleted successfully']);
    }
}
