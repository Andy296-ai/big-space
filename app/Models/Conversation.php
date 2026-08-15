<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Один из трёх видов переписки: общая на всех (единственная, см. миграцию
 * 2026_08_15_000007), командная (одна на Team, создаётся/удаляется вместе
 * с ней через TeamProvisioner) или личная 1:1 (создаётся по требованию,
 * см. DirectConversationController — только между людьми из одной команды,
 * кроме root, который пишет и получает от кого угодно).
 */
class Conversation extends Model
{
    public const TYPE_GLOBAL = 'global';

    public const TYPE_TEAM = 'team';

    public const TYPE_DIRECT = 'direct';

    public const TYPES = [self::TYPE_GLOBAL, self::TYPE_TEAM, self::TYPE_DIRECT];

    protected $fillable = ['type', 'team_id'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
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
