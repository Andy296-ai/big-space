<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_root
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// is_root намеренно не в списке — этот флаг не должен приходить из формы.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_root' => 'boolean',
        ];
    }

    /** @return HasMany<Space, $this> */
    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }

    /**
     * Узел этого пользователя в Admin-пространстве, если он там есть.
     *
     * @return HasOne<Node, $this>
     */
    public function linkedNode(): HasOne
    {
        return $this->hasOne(Node::class, 'linked_user_id');
    }

    /**
     * Чужие пространства, расшаренные этому пользователю — с ролью в pivot.
     *
     * @return BelongsToMany<Space, $this, SpaceCollaboratorPivot>
     */
    public function sharedSpaces(): BelongsToMany
    {
        return $this->belongsToMany(Space::class, 'space_collaborators')
            ->using(SpaceCollaboratorPivot::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Команды (для мессенджера), в которые пользователя добавил root.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')->withTimestamps();
    }

    /**
     * Разговоры в мессенджере, где пользователь — участник.
     *
     * @return BelongsToMany<Conversation, $this, ConversationParticipantPivot>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->using(ConversationParticipantPivot::class)
            ->withPivot('last_read_at')
            ->withTimestamps();
    }
}
