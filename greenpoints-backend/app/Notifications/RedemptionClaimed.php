<?php

namespace App\Notifications;

class RedemptionClaimed extends AppNotification
{
    public function __construct(private string $rewardName, private int $redemptionId)
    {
        parent::__construct();
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload(
            'info',
            'Reward collected',
            "{$this->rewardName} was handed over. Enjoy!",
            'redemption',
            $this->redemptionId,
        );
    }
}
