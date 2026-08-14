<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Неизменяемый след действий root'а (создание/удаление пользователей, сброс
 * пароля, удаление чужих пространств) — see docs/architecture note in
 * Admin\UserController. Запись не редактируется и не удаляется приложением.
 *
 * hash/prev_hash образуют хэш-цепочку: каждая запись коммитится к хэшу
 * предыдущей, поэтому задняя правка любой строки рвёт цепочку начиная с неё
 * — это обнаруживаемо через verifyChain(). Важно: это защита от подмены
 * (tamper-evidence), а не от неё (tamper-prevention) — booted()-хуки ниже
 * блокируют попытки поменять/удалить запись через сам Eloquent-инстанс, но
 * НЕ перехватывают ActivityLog::where(...)->update()/delete() через
 * query-builder (он не поднимает модельные события — тот же нюанс уже
 * отмечен в Admin\UserController) и тем более не защищает от прямого SQL с
 * правами суперюзера БД. Этот зазор оставлен сознательно: retention-пруner
 * (activity-log:prune) легитимно удаляет старые записи именно через
 * query-builder. Настоящее предотвращение подмены — уровень прав на БД
 * (REVOKE UPDATE, DELETE у роли приложения) или WORM-хранилище, это уже
 * эксплуатационный вопрос за пределами кода приложения.
 */
class ActivityLog extends Model
{
    /** У лога нет updated_at — событие зафиксировано раз и навсегда. */
    public const UPDATED_AT = null;

    public const ACTION_USER_CREATED = 'user.created';

    public const ACTION_USER_DELETED = 'user.deleted';

    public const ACTION_PASSWORD_RESET = 'user.password_reset';

    public const ACTION_SPACE_DELETED = 'space.deleted';

    public const ACTION_NODE_CREATED = 'node.created';

    public const ACTION_NODE_UPDATED = 'node.updated';

    public const ACTION_NODE_DELETED = 'node.deleted';

    public const ACTION_STRUCTURE_CHANGED = 'structure.changed';

    public const ACTION_COLLABORATOR_ADDED = 'collaborator.added';

    public const ACTION_COLLABORATOR_REMOVED = 'collaborator.removed';

    public const ACTION_COLLABORATOR_ROLE_CHANGED = 'collaborator.role_changed';

    public const ACTION_NODE_VIEWED = 'node.viewed';

    public const ACTION_NODE_REVISIONS_VIEWED = 'node.revisions_viewed';

    public const ACTION_ATTACHMENT_ACCESSED = 'attachment.accessed';

    public const ACTION_COLLABORATORS_VIEWED = 'space.collaborators_viewed';

    public const ACTION_SPACE_EXPORTED = 'space.exported';

    public const ACTION_ACTIVITY_LOG_VIEWED = 'activity_log.viewed';

    public const ACTION_LOG_PRUNED = 'activity_log.pruned';

    protected $fillable = [
        'actor_id',
        'space_id',
        'action',
        'subject_type',
        'subject_id',
        'meta',
        'prev_hash',
        'hash',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'subject_id' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException('ActivityLog rows are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new \RuntimeException('ActivityLog rows are append-only and cannot be deleted.');
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(
        ?User $actor,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $meta = [],
        ?int $spaceId = null,
    ): self {
        return DB::transaction(function () use ($actor, $action, $subjectType, $subjectId, $meta, $spaceId) {
            // lockForUpdate — без него две одновременные record() читают один
            // и тот же «последний хэш» и вставляют две записи с одинаковым
            // prev_hash, разветвляя цепочку (SQLite и так сериализует
            // писателей на уровне всей БД, так что цена этого блока — почти
            // ноль, но именно он делает цепочку по-настоящему безопасной).
            $prev = self::query()->orderByDesc('id')->lockForUpdate()->first(['hash']);
            $prevHash = $prev->hash ?? '';

            $createdAt = now()->format('Y-m-d H:i:s');

            $hash = self::digestInput(
                $prevHash,
                $actor?->id,
                $spaceId,
                $action,
                $subjectType,
                $subjectId,
                $meta,
                $createdAt,
            );

            return self::create([
                'actor_id' => $actor?->id,
                'space_id' => $spaceId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'meta' => $meta,
                'prev_hash' => $prevHash !== '' ? $prevHash : null,
                'hash' => $hash,
                'created_at' => $createdAt,
            ]);
        });
    }

    /**
     * Проходит цепочку от самой старой хэшированной записи вперёд и
     * пересчитывает каждый хэш. Возвращает первую запись, где пересчитанный
     * хэш разошёлся с сохранённым (подмена или порча), либо null, если
     * цепочка цела. Записи без hash (старее фичи или срезанные retention'ом)
     * пропускаются — первая найденная хэшированная запись становится точкой
     * доверия, дальше которой цепочка не проверяется (и не должна: более
     * ранних данных для сверки уже не существует).
     */
    public static function verifyChain(): ?self
    {
        // null, а не '' — важно: для самой первой найденной записи мы ещё
        // не знаем «истинный» prev_hash (данных для сверки нет, будь то
        // настоящий genesis или обрезанный retention'ом хвост). Поэтому для
        // первой итерации доверяем СОБСТВЕННОМУ prev_hash записи как точке
        // отсчёта и проверяем лишь её внутреннюю самосогласованность; для
        // всех следующих записей используется уже пересчитанный (реальный)
        // хэш предыдущей — вот это и есть цепочка.
        $prevHash = null;

        foreach (self::query()->whereNotNull('hash')->orderBy('id')->cursor() as $row) {
            /** @var self $row */
            $trustedPrev = $prevHash ?? ($row->prev_hash ?? '');

            $expected = self::digestInput(
                $trustedPrev,
                $row->actor_id,
                $row->space_id,
                $row->action,
                $row->subject_type,
                $row->subject_id,
                $row->meta ?? [],
                $row->created_at->format('Y-m-d H:i:s'),
            );

            if (! hash_equals($expected, $row->hash)) {
                return $row;
            }

            $prevHash = $row->hash;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function digestInput(
        string $prevHash,
        ?int $actorId,
        ?int $spaceId,
        string $action,
        ?string $subjectType,
        ?int $subjectId,
        array $meta,
        string $createdAt,
    ): string {
        return hash('sha256', implode('|', [
            $prevHash,
            $actorId ?? '',
            $spaceId ?? '',
            $action,
            $subjectType ?? '',
            $subjectId ?? '',
            json_encode($meta),
            $createdAt,
        ]));
    }
}
