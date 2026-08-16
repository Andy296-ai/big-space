<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Сигнал «блокировка узла изменилась» — тот же канал, что и SpaceUpdated,
 * но отдельным лёгким именем события: лок/анлок происходит на каждое
 * открытие/закрытие EditNodeModal, и гонять из-за этого полный refetch
 * графа для всех в пространстве было бы избыточным шумом. Поэтому лок
 * пишется через query-builder Node::where(...)->update(...), а не
 * Eloquent-метод update() — тот уже сам будит SpaceUpdated на каждый вызов
 * (см. Node::booted()), тот же фикс, что и в GraphRepository::applyDepths().
 */
class NodeLockChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array{id: int, name: string}|null  $lockedBy */
    public function __construct(
        public int $spaceId,
        public int $nodeId,
        public ?array $lockedBy,
    ) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('space.'.$this->spaceId)];
    }

    public function broadcastAs(): string
    {
        return 'node.lock.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['node_id' => $this->nodeId, 'locked_by' => $this->lockedBy];
    }
}
