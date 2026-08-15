<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Один из четырёх видов переписки: общая на всех (единственная, см. миграцию
 * 2026_08_15_000007), командная (одна на Team, создаётся/удаляется вместе
 * с ней через TeamProvisioner), личная 1:1 (создаётся по требованию,
 * см. DirectConversationController — только между людьми из одной команды,
 * кроме root, который пишет и получает от кого угодно) или обсуждение узла
 * (одна на Node, создаётся по требованию через кнопку «Обсудить» — доступ
 * у неё не по списку участников, а по доступу к пространству узла, см.
 * ConversationPolicy::access()).
 */
class Conversation extends Model
{
    public const TYPE_GLOBAL = 'global';

    public const TYPE_TEAM = 'team';

    public const TYPE_DIRECT = 'direct';

    public const TYPE_NODE = 'node';

    public const TYPES = [self::TYPE_GLOBAL, self::TYPE_TEAM, self::TYPE_DIRECT, self::TYPE_NODE];

    protected $fillable = ['type', 'team_id', 'node_id'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsToMany<User, $this, ConversationParticipantPivot> */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->using(ConversationParticipantPivot::class)
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Последнее сообщение — для превью в списке разговоров, без N+1 по каждому.
     *
     * @return HasOne<Message, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }
}
