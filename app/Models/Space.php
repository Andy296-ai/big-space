<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
