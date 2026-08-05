<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Сигнал «в этом пространстве что-то поменялось» — без диффа состояния.
 * Фронт в ответ просто перезапрашивает граф (см. resources/js/lib/echo.ts):
 * для масштаба этого приложения полный refetch надёжнее, чем поддерживать
 * инкрементальный патчинг состояния на клиенте.
 *
 * ShouldBroadcastNow — не через очередь, иначе живому обновлению нужен был
 * бы ещё и отдельный queue worker в дополнение к reverb:start.
 */
class SpaceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $spaceId) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('space.'.$this->spaceId)];
    }

    public function broadcastAs(): string
    {
        return 'space.updated';
    }
}
