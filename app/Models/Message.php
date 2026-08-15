<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_VOICE = 'voice';

    public const TYPE_VIDEO = 'video';

    public const TYPE_FILE = 'file';

    public const TYPES = [self::TYPE_TEXT, self::TYPE_VOICE, self::TYPE_VIDEO, self::TYPE_FILE];

    protected $fillable = ['conversation_id', 'sender_id', 'type', 'body'];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return HasOne<MessageAttachment, $this> */
    public function attachment(): HasOne
    {
        return $this->hasOne(MessageAttachment::class);
    }
}
