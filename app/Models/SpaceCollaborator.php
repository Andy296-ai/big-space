<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceCollaborator extends Model
{
    public const ROLE_VIEWER = 'viewer';

    public const ROLE_EDITOR = 'editor';

    public const ROLES = [self::ROLE_VIEWER, self::ROLE_EDITOR];

    protected $fillable = ['space_id', 'user_id', 'role'];

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
