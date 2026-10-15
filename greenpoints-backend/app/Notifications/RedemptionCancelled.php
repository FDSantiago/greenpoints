<?php

namespace App\Notifications;

/** Sent only when STAFF cancel a redemption; students cancelling their own know already. */
class RedemptionCancelled extends AppNotification
{
    public function __construct(private string $rewardName, private int $points, private int $redemptionId)
    {
        parent::__construct();
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload(
            'alert',
            'Redemption cancelled',
            "{$this->rewardName} was cancelled by staff. {$this->points} points were refunded.",
            'redemption',
            $this->redemptionId,
        );
    }
}
