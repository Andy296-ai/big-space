<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_VOICE = 'voice';

    public const TYPE_VIDEO = 'video';

    public const TYPE_FILE = 'file';

    public const TYPES = [self::TYPE_TEXT, self::TYPE_VOICE, self::TYPE_VIDEO, self::TYPE_FILE];

    protected $fillable = ['conversation_id', 'sender_id', 'type', 'body', 'edited_at', 'deleted_at'];

    protected $casts = [
        'edited_at' => 'datetime',
        // Не Eloquent SoftDeletes — намеренно, см. миграцию. Обычный
        // datetime-каст, видимость по роли решает MessageController::serialize().
        'deleted_at' => 'datetime',
    ];

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

    /**
     * Узлы, упомянутые в теле через [[node:ID]] — парсится при сохранении/
     * редактировании, см. MessageController.
     *
     * @return BelongsToMany<Node, $this>
     */
    public function referencedNodes(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'message_node_references');
    }
}
