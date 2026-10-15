<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Base for all in-app (database) notifications.
 *
 * Stored payload (the frontend renders these):
 *   style : 'info' | 'alert' | 'goal'   (white/green, red, gold cards in the wireframe)
 *   title : short headline
 *   body  : one-line detail
 *   link  : { type: 'submission'|'redemption'|'wallet', id: string|int|null } for click-through
 */
abstract class AppNotification extends Notification
{
    public function __construct()
    {
        // Time-ordered ids, so "newest first" is exact even for notifications created in the same second.
        $this->id = (string) Str::orderedUuid();
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    protected function payload(string $style, string $title, string $body, string $linkType, string|int|null $linkId = null): array
    {
        return [
            'style' => $style,
            'title' => $title,
            'body' => $body,
            'link' => ['type' => $linkType, 'id' => $linkId],
        ];
    }
}
