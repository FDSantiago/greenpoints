<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Sends a notification without ever breaking the action that triggered it:
 * if the notification can't be stored, the error is logged and the request carries on
 * (the points were already awarded / the redemption already updated).
 */
final class Notifier
{
    public static function send(?User $user, Notification $notification): void
    {
        if (! $user) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
