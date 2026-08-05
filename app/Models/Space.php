<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string|null $role Роль текущего пользователя в этом пространстве
 *                             (owner/editor/viewer) — не колонка, проставляется контроллером под
 *                             конкретного пользователя перед отдачей на фронт (см. Space::roleFor()).
 * @property string|null $owner_name Имя владельца — проставляется рядом с
 *                                   $role только для расшаренных пространств в списках.
 * @property-read SpaceCollaboratorPivot|null $pivot Присутствует только у
 *     результатов User::sharedSpaces() — это pivot-строка из space_collaborators.
 */
class Space extends Model
{
    /** Строгое дерево: у узла не больше одного родителя, циклы запрещены. */
    public const STRUCTURE_TREE = 'tree';

    /** Направленный ациклический граф: несколько родителей, циклы запрещены. */
    public const STRUCTURE_DAG = 'dag';

    /** Сеть: направление связи необязательно, циклы разрешены. */
    public const STRUCTURE_NETWORK = 'network';

    /**
     * Уровневый (градуированный) граф: каждое ребро соединяет строго соседние
     * уровни, то есть depth ребёнка всегда равен depth родителя + 1. Циклы
     * при этом невозможны сами по себе — вдоль пути глубина только растёт.
     */
    public const STRUCTURE_LEVELED = 'leveled';

    public const STRUCTURES = [
        self::STRUCTURE_TREE,
        self::STRUCTURE_LEVELED,
        self::STRUCTURE_DAG,
        self::STRUCTURE_NETWORK,
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'structure',
        'user_id',
    ];

    protected $attributes = [
        'structure' => self::STRUCTURE_DAG,
    ];

    protected $casts = [
        'is_admin' => 'boolean',
    ];

    /** @return HasMany<Node, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    /** @return HasMany<Edge, $this> */
    public function edges(): HasMany
    {
        return $this->hasMany(Edge::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Узел в Admin-пространстве, представляющий именно это пространство.
     *
     * @return HasOne<Node, $this>
     */
    public function linkedNode(): HasOne
    {
        return $this->hasOne(Node::class, 'linked_space_id');
    }

    /**
     * Пользователи, которым владелец расшарил пространство — с ролью в pivot.
     *
     * @return BelongsToMany<User, $this, SpaceCollaboratorPivot>
     */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'space_collaborators')
            ->using(SpaceCollaboratorPivot::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Может смотреть: владелец, root или любой участник (viewer/editor). */
    public function isAccessibleBy(User $user): bool
    {
        return $user->is_root
            || $this->user_id === $user->id
            || $this->collaborators()->where('user_id', $user->id)->exists();
    }

    /**
     * Роль пользователя в этом пространстве для фронтенда (кнопки
     * скрываются по ней) — root везде "owner", у него полные права
     * независимо от того, кому пространство принадлежит на самом деле.
     */
    public function roleFor(User $user): ?string
    {
        if ($user->is_root || $this->user_id === $user->id) {
            return 'owner';
        }

        $pivot = $this->collaborators()->where('user_id', $user->id)->first()?->pivot;

        return $pivot?->role;
    }

    /** Может менять граф: владелец, root или участник с ролью editor. */
    public function isEditableBy(User $user): bool
    {
        return $user->is_root
            || $this->user_id === $user->id
            || $this->collaborators()->where('user_id', $user->id)->where('role', SpaceCollaborator::ROLE_EDITOR)->exists();
    }

    /** В дереве второй родитель запрещён. */
    public function allowsMultipleParents(): bool
    {
        return $this->structure !== self::STRUCTURE_TREE;
    }

    /** Циклы имеют смысл только в сети — у остальных структур они ломают обход. */
    public function allowsCycles(): bool
    {
        return $this->structure === self::STRUCTURE_NETWORK;
    }

    /** Ребро обязано соединять соседние уровни (depth ребёнка = depth родителя + 1). */
    public function requiresAdjacentLevels(): bool
    {
        return $this->structure === self::STRUCTURE_LEVELED;
    }
}
