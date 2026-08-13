<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Сигнал «на этом узле новый комментарий» — на том же канале, что и
 * SpaceUpdated, но отдельным именем события, чтобы открытая панель узла
 * могла перезапросить только комментарии, не весь граф.
 */
class CommentPosted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $spaceId,
        public int $nodeId,
    ) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('space.'.$this->spaceId)];
    }

    public function broadcastAs(): string
    {
        return 'comment.posted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['node_id' => $this->nodeId];
    }
}
