<?php

namespace App\Notifications;

class PointsAdjusted extends AppNotification
{
    public function __construct(private int $points, private string $note, private int $balance)
    {
        parent::__construct();
    }

    public function toArray(object $notifiable): array
    {
        $sign = $this->points > 0 ? '+' : '';

        return $this->payload(
            $this->points > 0 ? 'goal' : 'alert',
            "Points adjusted: {$sign}{$this->points}",
            "{$this->note} Your balance is now {$this->balance}.",
            'wallet',
        );
    }
}
