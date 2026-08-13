<?php

namespace App\Notifications;

use App\Models\Space;
use Illuminate\Notifications\Notification;

/**
 * «Вам открыли доступ к пространству» — только database-канал: у Laravel
 * канал 'broadcast' для уведомлений всегда уходит через очередь, а очередь
 * здесь никто не воркерит. Живой сигнал шлём отдельно — см. NotificationPosted,
 * тот же приём "сигнал без пейлоада, клиент перезапрашивает", что и везде в проекте.
 */
class SpaceAccessGranted extends Notification
{
    public function __construct(
        public Space $space,
        public string $role,
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
            'space_id' => $this->space->id,
            'space_slug' => $this->space->slug,
            'space_name' => $this->space->name,
            'role' => $this->role,
            'owner_name' => $this->space->user?->name,
        ];
    }
}
