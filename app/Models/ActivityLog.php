<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Неизменяемый след действий root'а (создание/удаление пользователей, сброс
 * пароля, удаление чужих пространств) — see docs/architecture note in
 * Admin\UserController. Запись не редактируется и не удаляется приложением.
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

    public const ACTION_NODE_DELETED = 'node.deleted';

    public const ACTION_STRUCTURE_CHANGED = 'structure.changed';

    public const ACTION_COLLABORATOR_ADDED = 'collaborator.added';

    public const ACTION_COLLABORATOR_REMOVED = 'collaborator.removed';

    public const ACTION_COLLABORATOR_ROLE_CHANGED = 'collaborator.role_changed';

    protected $fillable = [
        'actor_id',
        'space_id',
        'action',
        'subject_type',
        'subject_id',
        'meta',
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
        return self::create([
            'actor_id' => $actor?->id,
            'space_id' => $spaceId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta,
        ]);
    }
}
