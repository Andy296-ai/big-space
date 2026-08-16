<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * @упоминание — в сообщении мессенджера или в комментарии к узлу, один
 * полиморфный класс на оба контекста (единственный прецедент до этого,
 * SpaceAccessGranted, тоже не про сообщения/комментарии, так что заводить
 * отдельные MessageMention/CommentMention классы было не с чего). Только
 * database-канал — та же дисциплина, что и у SpaceAccessGranted: канал
 * 'broadcast' у Notification всегда уходит через очередь, а воркер здесь
 * никогда не запускается. Живой сигнал — отдельно, см. NotificationPosted.
 */
class UserMentioned extends Notification
{
    public function __construct(
        public string $contextType,
        public int $contextId,
        public string $excerpt,
        public string $mentionedByName,
        public ?int $conversationId = null,
        public ?int $spaceId = null,
        public ?string $spaceSlug = null,
        public ?int $nodeId = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'context_type' => $this->contextType,
            'context_id' => $this->contextId,
            'excerpt' => $this->excerpt,
            'mentioned_by_name' => $this->mentionedByName,
            'conversation_id' => $this->conversationId,
            'space_id' => $this->spaceId,
            'space_slug' => $this->spaceSlug,
            'node_id' => $this->nodeId,
        ];
    }
}
