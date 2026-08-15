<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Сигнал «в разговоре X новое сообщение» — без текста сообщения в пейлоаде,
 * тот же приём "сигнал без данных, клиент перезапрашивает", что и у
 * NotificationPosted/CommentPosted/SpaceUpdated. ShouldBroadcastNow, не
 * ShouldBroadcast — в проекте QUEUE_CONNECTION=database, но воркер никогда
 * не запускается, поэтому всё, что уходит в очередь, зависает навсегда.
 *
 * Рассылается персонально каждому участнику по уже существующему приватному
 * каналу App.Models.User.{id} — новых каналов не требует. Для общего чата
 * это означает рассылку по каналу на каждого пользователя системы; при
 * реальном росте числа пользователей (не актуально для этого проекта)
 * это стоит заменить на один общий канал messenger.global.
 */
class MessagePosted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<int, int>  $recipientUserIds */
    public function __construct(
        public int $conversationId,
        public array $recipientUserIds,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $userId) => new PrivateChannel("App.Models.User.{$userId}"),
            $this->recipientUserIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'message.posted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}
