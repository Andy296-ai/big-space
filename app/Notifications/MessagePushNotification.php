<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Пуш о новом сообщении — класс сознательно НЕ ShouldQueue: канал шлёт
 * синхронно внутри запроса, та же причина, что у MessagePosted (ShouldBroadcastNow) —
 * воркер очереди в проекте никогда не запускается, всё, что туда уходит,
 * зависает навсегда.
 *
 * Текст — фиксированно на английском вне зависимости от языка получателя:
 * настройки интерфейса живут только в localStorage браузера (см.
 * app.blade.php), у сервера нет сохранённого языка пользователя. И
 * APP_LOCALE, и DEFAULT_SETTINGS.lang по умолчанию — 'en', так что это не
 * произвольный выбор, а совпадение с уже принятым дефолтом приложения.
 */
class MessagePushNotification extends Notification
{
    public function __construct(private readonly Message $message) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->message->sender->name)
            ->icon('/icon-192.png')
            ->body($this->bodyPreview())
            ->data(['url' => "/?conversation={$this->message->conversation_id}"]);
    }

    private function bodyPreview(): string
    {
        return match ($this->message->type) {
            Message::TYPE_VOICE => 'sent a voice message',
            Message::TYPE_VIDEO => 'sent a video message',
            Message::TYPE_FILE => 'sent a file',
            default => Str::limit((string) $this->message->body, 80),
        };
    }
}
