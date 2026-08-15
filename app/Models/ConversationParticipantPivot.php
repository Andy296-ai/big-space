<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $conversation_id
 * @property int $user_id
 * @property Carbon|null $last_read_at
 */
class ConversationParticipantPivot extends Pivot
{
    protected $table = 'conversation_participants';

    protected $casts = [
        'last_read_at' => 'datetime',
    ];
}
