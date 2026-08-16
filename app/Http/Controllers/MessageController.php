<?php

namespace App\Http\Controllers;

use App\Concerns\ResolvesUserMentions;
use App\Events\MessagePosted;
use App\Events\NotificationPosted;
use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Node;
use App\Models\Space;
use App\Models\User;
use App\Notifications\MessagePushNotification;
use App\Notifications\UserMentioned;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    use ResolvesUserMentions;

    /** [[node:123]] — упоминание узла в теле сообщения, парсится при сохранении/редактировании. */
    private const MENTION_PATTERN = '/\[\[node:(\d+)\]\]/';

    /** Общий eager-load для отдачи сообщения клиенту — везде одинаковый набор связей. */
    private const RESPONSE_WITH = ['sender:id,name', 'attachment', 'referencedNodes:id,title,space_id', 'referencedNodes.space:id,slug', 'reactions:id,message_id,user_id,emoji'];

    /**
     * Фиксированный набор — не пикер, просто v-for по константному массиву и
     * на фронте, и здесь: любой другой текст в это поле не пройдёт валидацию.
     */
    private const ALLOWED_REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '🙏', '🎉'];

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 100;

    /** 200 МБ — тот же потолок, что и у вложений к узлам (AttachmentController::MAX_KILOBYTES); тем же лимитом делится video. */
    private const MAX_FILE_KILOBYTES = 204800;

    /** Голосовые — короткие, 20 МБ с большим запасом даже для длинной записи на opus. */
    private const MAX_VOICE_KILOBYTES = 20480;

    /** Голосовое — не многочасовая запись: 10 минут потолок. */
    private const MAX_VOICE_DURATION_MS = 10 * 60 * 1000;

    /** Видео-сообщение — короткая заметка, а не полноценный ролик: 5 минут потолок. */
    private const MAX_VIDEO_DURATION_MS = 5 * 60 * 1000;

    /**
     * Настоящий allowlist по содержимому файла (Laravel's mimes:/mimetypes:
     * используют content-sniffing через finfo, а не доверяют расширению или
     * Content-Type от клиента) — в отличие от NodeAttachment::store(), у
     * которого такой проверки нет вообще (известный, сознательно не
     * исправленный там пробел из более раннего security-прохода). Здесь —
     * новый путь загрузки, повторять пробел незачем.
     */
    private const ALLOWED_FILE_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm',
        'md', 'txt', 'html', 'htm', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'csv',
    ];

    /**
     * Только mimetypes: (не mimes:) — тут решает именно точный MIME, не его
     * производное расширение. Symfony's MimeTypes::getExtensions() отражает
     * audio/webm в "weba", а не "webm" (MediaRecorder на большинстве браузеров
     * пишет ровно audio/webm), так что mimes:webm его бы отверг — не подмена,
     * а особенность маппинга контейнер→расширение. video/webm в allowlist'е
     * по той же причине: контейнер (Matroska) от чисто аудио не отличить.
     * То же для audio/mp4 vs video/mp4 (Safari-фолбэк).
     */
    private const ALLOWED_VOICE_MIMES = [
        'audio/webm', 'video/webm',
        'audio/ogg', 'video/ogg',
        'audio/mp4', 'video/mp4',
        'audio/x-m4a', 'audio/m4a',
        'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave',
    ];

    private const ALLOWED_VIDEO_MIMES = ['video/webm', 'video/mp4', 'video/ogg'];

    /**
     * Курсорная пагинация по id — see план: "before_id cursor", не offset
     * (тот дрейфует под параллельными вставками). ?around_id=X — отдельный
     * режим «прыжка»: N/2 до и N/2 после конкретного сообщения разом, для
     * перехода из закреплённых/поиска к историческому сообщению вне текущего
     * загруженного окна — без него пришлось бы догружать before_id постранично.
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $limit = min((int) $request->query('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $aroundId = $request->query('around_id');

        if ($aroundId !== null) {
            $messages = $this->messagesAround($conversation, (int) $aroundId, $limit);

            return response()->json($this->serializeMany($messages, $conversation, $request->user()));
        }

        $beforeId = $request->query('before_id');

        $messages = $conversation->messages()
            ->with(self::RESPONSE_WITH)
            ->when($beforeId, fn ($q) => $q->where('id', '<', (int) $beforeId))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return response()->json($this->serializeMany($messages, $conversation, $request->user()));
    }

    /** @return Collection<int, Message> */
    private function messagesAround(Conversation $conversation, int $targetId, int $limit): Collection
    {
        $half = intdiv($limit, 2);

        $before = $conversation->messages()
            ->with(self::RESPONSE_WITH)
            ->where('id', '<=', $targetId)
            ->latest('id')
            ->limit($half + 1)
            ->get()
            ->reverse();

        $after = $conversation->messages()
            ->with(self::RESPONSE_WITH)
            ->where('id', '>', $targetId)
            ->oldest('id')
            ->limit($half)
            ->get();

        return $before->concat($after)->values();
    }

    /**
     * Поиск по телу внутри разговора — только текстовые и неудалённые
     * сообщения (даже root не должен получать восстановленные плашки
     * тобстоунов в выдаче поиска). Экранирование LIKE — тот же приём,
     * что и в SearchController::index().
     */
    public function search(Request $request, Conversation $conversation): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $query).'%';

        $messages = $conversation->messages()
            ->with(self::RESPONSE_WITH)
            ->where('type', Message::TYPE_TEXT)
            ->whereNull('deleted_at')
            ->whereRaw('body LIKE ? ESCAPE ?', [$needle, '\\'])
            ->latest('id')
            ->limit(self::DEFAULT_LIMIT)
            ->get();

        return response()->json($this->serializeMany($messages, $conversation, $request->user()));
    }

    /** Закреплённые — отдельно от курсорной пагинации: старое закреплённое сообщение не обязано попадать в текущую подгруженную страницу. */
    public function pinned(Request $request, Conversation $conversation): JsonResponse
    {
        $messages = $conversation->messages()
            ->with(self::RESPONSE_WITH)
            ->whereNotNull('pinned_at')
            ->orderByDesc('pinned_at')
            ->get();

        return response()->json($this->serializeMany($messages, $conversation, $request->user()));
    }

    /**
     * Всё для открытия треда одним запросом: сообщения, закреплённые,
     * участники (кандидаты на @упоминание) и "прочитано" под своим последним
     * сообщением — плюс отметка "прочитано" самим фактом открытия, как
     * markRead(). Раньше это было 5 отдельных запросов подряд/параллельно
     * (messages + markRead + pinned + participants + read-by); обычный
     * браузер ещё терпит параллельность, но у WebView в десктопном
     * Tauri-клиенте лимит одновременных соединений к одному хосту заметно
     * скромнее — лишние запросы там просто вставали в очередь друг за
     * другом. Один запрос снимает зависимость от лимита клиента совсем.
     */
    public function bootstrap(Request $request, Conversation $conversation): JsonResponse
    {
        $viewer = $request->user();
        $limit = min((int) $request->query('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT);

        $messages = $conversation->messages()
            ->with(self::RESPONSE_WITH)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $pinned = $conversation->messages()
            ->with(self::RESPONSE_WITH)
            ->whereNotNull('pinned_at')
            ->orderByDesc('pinned_at')
            ->get();

        $participants = $conversation->participants()->orderBy('users.name')->get(['users.id', 'users.name']);

        $lastMine = $messages->last(fn (Message $m) => $m->sender_id === $viewer->id);
        $readBy = [];

        if ($lastMine) {
            $readBy = $conversation->participants()
                ->where('users.id', '!=', $viewer->id)
                ->wherePivot('last_read_at', '>=', $lastMine->created_at)
                ->get(['users.id', 'users.name']);
        }

        $conversation->participants()->updateExistingPivot($viewer->id, ['last_read_at' => now()]);

        return response()->json([
            'messages' => $this->serializeMany($messages, $conversation, $viewer),
            'pinned' => $this->serializeMany($pinned, $conversation, $viewer),
            'participants' => $participants,
            'read_by' => $readBy,
        ]);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $type = $request->input('type', Message::TYPE_TEXT);

        $message = match ($type) {
            Message::TYPE_FILE => $this->storeFileMessage($request, $conversation),
            Message::TYPE_VOICE => $this->storeVoiceMessage($request, $conversation),
            Message::TYPE_VIDEO => $this->storeVideoMessage($request, $conversation),
            default => $this->storeTextMessage($request, $conversation),
        };

        $recipientIds = $this->broadcastConversationChanged($conversation, $request->user());
        $this->sendPushNotifications($recipientIds, $message);
        $this->notifyMentionedUsers($message, $conversation, $request->user());

        return response()->json(
            $this->serialize($message->load(self::RESPONSE_WITH), $conversation, $request->user()),
            201,
        );
    }

    /**
     * Редактирование — только автор, без окна по времени (как в Telegram) и
     * без права root на чужие сообщения: переписать чужие слова — не то же
     * самое, что их удалить, здесь такого прецедента нигде больше нет.
     */
    public function update(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        abort_unless($message->conversation_id === $conversation->id, 404);
        abort_unless($message->type === Message::TYPE_TEXT, 422);
        abort_unless($message->deleted_at === null, 404);
        abort_unless($message->sender_id === $request->user()->id, 403);

        $request->merge(['body' => trim((string) $request->input('body', ''))]);

        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $message->update(['body' => $validated['body'], 'edited_at' => now()]);

        // Правка могла добавить или убрать упоминания — sync(), не attach(),
        // иначе старые ссылки из дотекстовой версии тела просто накапливались бы.
        $this->syncNodeReferences($message, $validated['body'], $request->user());

        $this->broadcastConversationChanged($conversation, $request->user());

        return response()->json(
            $this->serialize($message->fresh(self::RESPONSE_WITH), $conversation, $request->user()),
        );
    }

    /**
     * Удаление — плашка ("сообщение удалено"), не настоящее удаление
     * строки: root должен продолжать видеть исходное содержимое (см.
     * serialize()), а факт удаления попадает в аудит-лог. Автор или root —
     * та же дисциплина, что и у ConversationPolicy::access/Space::isAccessibleBy.
     */
    public function destroy(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        abort_unless($message->conversation_id === $conversation->id, 404);
        abort_unless($message->deleted_at === null, 404);

        $user = $request->user();
        abort_unless($message->sender_id === $user->id || $user->is_root, 403);

        // Закреплённое удалённое сообщение в баннере — бессмысленно, снимаем закрепление заодно.
        $message->update(['deleted_at' => now(), 'pinned_at' => null]);

        ActivityLog::record($user, ActivityLog::ACTION_MESSAGE_DELETED, 'message', $message->id, [
            'conversation_id' => $conversation->id,
            'preview' => $message->type === Message::TYPE_TEXT
                ? Str::limit((string) $message->body, 50)
                : "[{$message->type}]",
        ]);

        $this->broadcastConversationChanged($conversation, $user);

        return response()->json(['message' => 'ok']);
    }

    /**
     * Закрепление — тумблер по существованию pinned_at, доступен любому
     * участнику разговора, не только автору или root: небольшой внутренний
     * инструмент, роли модератора здесь нет ни у чего в мессенджере.
     */
    public function pin(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        abort_unless($message->conversation_id === $conversation->id, 404);
        abort_unless($message->deleted_at === null, 404);

        $message->update(['pinned_at' => $message->pinned_at === null ? now() : null]);

        $this->broadcastConversationChanged($conversation, $request->user());

        return response()->json(
            $this->serialize($message->fresh(self::RESPONSE_WITH), $conversation, $request->user()),
        );
    }

    /**
     * Реакция — тумблер по существованию строки (message_id, user_id, emoji):
     * повторный тап тем же эмодзи снимает её же. Один пользователь может
     * оставить несколько разных эмодзи на одно сообщение (уникальность —
     * по тройке, не по паре message_id+user_id).
     */
    public function react(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        abort_unless($message->conversation_id === $conversation->id, 404);
        abort_unless($message->deleted_at === null, 404);

        $validated = $request->validate([
            'emoji' => 'required|string|in:'.implode(',', self::ALLOWED_REACTION_EMOJIS),
        ]);

        $user = $request->user();
        $existing = $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $validated['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            try {
                $message->reactions()->create(['user_id' => $user->id, 'emoji' => $validated['emoji']]);
            } catch (QueryException $e) {
                // Гонка: два быстрых тапа по одному эмодзи проходят проверку
                // "уже есть?" оба разом, до того как первый create() успеет
                // закоммититься — уникальный индекс (message_id, user_id,
                // emoji) отклоняет второй. Результат совпадает с целью
                // (реакция существует), так что это не ошибка — глушим
                // только именно нарушение уникальности, не любой QueryException.
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }
            }
        }

        $this->broadcastConversationChanged($conversation, $user);

        return response()->json(
            $this->serialize($message->fresh(self::RESPONSE_WITH), $conversation, $user),
        );
    }

    /**
     * «Прочитано кем» — по требованию, для конкретного сообщения (фронт
     * зовёт это только для своего последнего отправленного в открытом
     * треде, не по каждому историческому). Переиспользует существующую
     * conversation_participants.last_read_at, отдельной таблицы не заводим.
     * Без нового broadcast-события — markRead() дёргается куда чаще
     * отправки сообщений (при каждом открытии/скролле), а галочка
     * освежится с любым следующим поводом перезагрузить тред.
     */
    public function readBy(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        abort_unless($message->conversation_id === $conversation->id, 404);

        $readers = $conversation->participants()
            ->where('users.id', '!=', $message->sender_id)
            ->wherePivot('last_read_at', '>=', $message->created_at)
            ->get(['users.id', 'users.name']);

        return response()->json($readers);
    }

    /** @return array<int, int> */
    private function broadcastConversationChanged(Conversation $conversation, User $actor): array
    {
        $conversation->touch();

        $recipientIds = $conversation->participants()
            ->where('user_id', '!=', $actor->id)
            ->pluck('users.id')
            ->all();

        if ($recipientIds !== []) {
            MessagePosted::dispatch($conversation->id, $recipientIds);
        }

        return $recipientIds;
    }

    /**
     * Пуш только на новые сообщения (store), не на правки/удаления — «кто-то
     * отредактировал сообщение» не то событие, ради которого стоит будить
     * телефон. Изолировано try/catch: это внешний сервис (push-провайдеры
     * браузеров), а не имеет права ронять сохранение и рассылку самого
     * сообщения — то, что реально критично для функции мессенджера.
     *
     * @param  array<int, int>  $recipientIds
     */
    private function sendPushNotifications(array $recipientIds, Message $message): void
    {
        if ($recipientIds === []) {
            return;
        }

        try {
            Notification::send(
                User::whereIn('id', $recipientIds)->get(),
                new MessagePushNotification($message),
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send web push notifications for a message.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Создаёт связи message_node_references по [[node:ID]] в теле. Ссылка
     * ставится только на узел, пространство которого доступно ОТПРАВИТЕЛЮ —
     * иначе синтаксис упоминания стал бы оракулом перебора чужих id (кто-то
     * узнаёт, что узел существует и как называется, просто подставляя ID).
     * Видимость для ЧИТАТЕЛЕЙ — отдельная проверка, в serialize()/
     * serializeMany(), потому что участники общего/командного чата не
     * обязаны разделять доступ к пространствам с отправителем.
     */
    private function syncNodeReferences(Message $message, string $body, User $sender): void
    {
        preg_match_all(self::MENTION_PATTERN, $body, $matches);
        $mentionedIds = array_unique(array_map('intval', $matches[1]));

        if ($mentionedIds === []) {
            $message->referencedNodes()->sync([]);

            return;
        }

        $nodes = Node::whereIn('id', $mentionedIds)->get(['id', 'space_id']);
        $accessibleSpaceIds = Space::accessibleAmong($nodes->pluck('space_id'), $sender);

        $allowedNodeIds = $nodes
            ->filter(fn (Node $n) => in_array($n->space_id, $accessibleSpaceIds, true))
            ->pluck('id');

        $message->referencedNodes()->sync($allowedNodeIds);
    }

    /**
     * @упоминания людей — только при первой отправке (store), не при
     * правке: правка сообщения не должна будить того, кто уже видел его
     * раньше. Круг, кого можно упомянуть — участники ИМЕННО этого разговора,
     * не все пользователи разом.
     */
    private function notifyMentionedUsers(Message $message, Conversation $conversation, User $sender): void
    {
        if ($message->type !== Message::TYPE_TEXT || ! $message->body) {
            return;
        }

        $participants = $conversation->participants()->get(['users.id', 'users.name']);
        $mentioned = $this->resolveUserMentions($message->body, $participants->pluck('name', 'id'));

        foreach ($mentioned as $entry) {
            if ($entry['id'] === $sender->id) {
                continue;
            }

            $user = $participants->firstWhere('id', $entry['id']);

            if (! $user) {
                continue;
            }

            $user->notify(new UserMentioned(
                contextType: 'message',
                contextId: $message->id,
                excerpt: Str::limit($message->body, 100),
                mentionedByName: $sender->name,
                conversationId: $conversation->id,
            ));
            NotificationPosted::dispatch($user->id);
        }
    }

    /**
     * Сериализует страницу сообщений разом — доступность пространств
     * упомянутых узлов считается ОДНИМ батч-запросом на всю страницу
     * (Space::accessibleAmong), а не по сообщению: иначе это был бы
     * настоящий N+1 при нескольких упоминаниях на нескольких сообщениях.
     * Список участников для @упоминаний людей — тоже один запрос на всю
     * страницу, не на сообщение.
     *
     * @param  Collection<int, Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function serializeMany(Collection $messages, Conversation $conversation, User $viewer): array
    {
        $spaceIds = $messages->flatMap(fn (Message $m) => $m->referencedNodes->pluck('space_id'));
        $accessibleSpaceIds = Space::accessibleAmong($spaceIds, $viewer);
        $participantNames = $conversation->participants()->pluck('users.name', 'users.id');

        return $messages->map(fn (Message $m) => $this->serialize($m, $conversation, $viewer, $accessibleSpaceIds, $participantNames))->all();
    }

    /**
     * Единая точка отдачи сообщения клиенту — из неё же и только из неё
     * должны уходить node_references и content-поля, иначе легко случайно
     * просочить нефильтрованную связь под другим ключом (см. предупреждение
     * в плане: голый toArray() отдал бы ВСЮ referencedNodes как есть).
     *
     * @param  array<int, int>|null  $accessibleSpaceIds  батч на всю страницу — см. serializeMany(); null для одиночного сообщения (store/update)
     * @param  Collection<int, string>|null  $participantNames  id => name, батч на всю страницу; null для одиночного сообщения
     * @return array<string, mixed>
     */
    private function serialize(Message $message, Conversation $conversation, User $viewer, ?array $accessibleSpaceIds = null, ?Collection $participantNames = null): array
    {
        $accessibleSpaceIds ??= Space::accessibleAmong($message->referencedNodes->pluck('space_id'), $viewer);
        $participantNames ??= $conversation->participants()->pluck('users.name', 'users.id');

        $array = $message->toArray();
        unset($array['referenced_nodes'], $array['reactions']);

        $isHiddenTombstone = $message->deleted_at !== null && ! $viewer->is_root;

        if ($isHiddenTombstone) {
            $array['body'] = null;
            $array['attachment'] = null;
            $array['node_references'] = [];
            $array['reactions'] = [];
            $array['user_mentions'] = [];

            return $array;
        }

        $array['node_references'] = $message->referencedNodes
            ->filter(fn (Node $n) => in_array($n->space_id, $accessibleSpaceIds, true))
            ->map(fn (Node $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'space_id' => $n->space_id,
                'space_slug' => $n->space->slug,
            ])
            ->values();

        $array['reactions'] = $message->reactions
            ->groupBy('emoji')
            ->map(fn (Collection $group, string $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'reacted_by_me' => $group->contains('user_id', $viewer->id),
            ])
            ->values();

        $array['user_mentions'] = $this->resolveUserMentions($message->body, $participantNames);

        return $array;
    }

    private function storeTextMessage(Request $request, Conversation $conversation): Message
    {
        // Триммим ДО валидации: иначе строка из одних пробелов проходит
        // required (тот отвергает только null/пустую строку/пустой массив) —
        // тот же фикс, что уже применён в NodeCommentController::store().
        $request->merge(['body' => trim((string) $request->input('body', ''))]);

        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'type' => Message::TYPE_TEXT,
            'body' => $validated['body'],
        ]);

        $this->syncNodeReferences($message, $validated['body'], $request->user());

        return $message;
    }

    private function storeFileMessage(Request $request, Conversation $conversation): Message
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_FILE_KILOBYTES,
                'mimes:'.implode(',', self::ALLOWED_FILE_EXTENSIONS),
            ],
        ]);

        return $this->createMediaMessage($request, $conversation, Message::TYPE_FILE, $validated['file']);
    }

    private function storeVoiceMessage(Request $request, Conversation $conversation): Message
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_VOICE_KILOBYTES,
                'mimetypes:'.implode(',', self::ALLOWED_VOICE_MIMES),
            ],
            'duration_ms' => 'nullable|integer|min:0|max:'.self::MAX_VOICE_DURATION_MS,
        ]);

        return $this->createMediaMessage(
            $request,
            $conversation,
            Message::TYPE_VOICE,
            $validated['file'],
            $validated['duration_ms'] ?? null,
        );
    }

    private function storeVideoMessage(Request $request, Conversation $conversation): Message
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_FILE_KILOBYTES,
                'mimetypes:'.implode(',', self::ALLOWED_VIDEO_MIMES),
            ],
            'duration_ms' => 'nullable|integer|min:0|max:'.self::MAX_VIDEO_DURATION_MS,
        ]);

        return $this->createMediaMessage(
            $request,
            $conversation,
            Message::TYPE_VIDEO,
            $validated['file'],
            $validated['duration_ms'] ?? null,
        );
    }

    private function createMediaMessage(
        Request $request,
        Conversation $conversation,
        string $type,
        UploadedFile $file,
        ?int $durationMs = null,
    ): Message {
        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'type' => $type,
            'body' => null,
        ]);

        $path = $file->store("messenger/{$conversation->id}/{$message->id}", MessageAttachment::DISK);

        MessageAttachment::create([
            'message_id' => $message->id,
            'label' => $file->getClientOriginalName(),
            'path' => $path,
            'size' => $file->getSize(),
            'format' => substr($file->getClientOriginalExtension(), 0, 8),
            // Определено по содержимому (finfo), не по расширению и не по
            // заголовку от клиента — см. класс-комментарий выше.
            'mime' => (string) $file->getMimeType(),
            'duration_ms' => $durationMs,
        ]);

        return $message;
    }

    /** Отмечает разговор прочитанным до текущего момента для текущего пользователя. */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->participants()->updateExistingPivot($request->user()->id, [
            'last_read_at' => now(),
        ]);

        return response()->json(['message' => 'ok']);
    }
}
