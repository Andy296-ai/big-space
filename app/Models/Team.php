<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Группа пользователей, которую создаёт root — не имеет собственного
 * представления в графе (в отличие от User/Space), только для мессенджера.
 * См. App\Services\TeamProvisioner — создание/удаление и синхронизация
 * участников с групповым разговором идут только через него.
 */
class Team extends Model
{
    protected $fillable = ['name', 'description'];

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withTimestamps();
    }

    /** @return HasOne<Conversation, $this> */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }
}
