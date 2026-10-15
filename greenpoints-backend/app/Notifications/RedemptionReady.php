<?php

namespace App\Notifications;

class RedemptionReady extends AppNotification
{
    public function __construct(
        private string $rewardName,
        private string $code,
        private string $location,
        private int $redemptionId,
    ) {
        parent::__construct();
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload(
            'info',
            'Your reward is ready for pickup',
            "{$this->rewardName}: show code {$this->code} at {$this->location}.",
            'redemption',
            $this->redemptionId,
        );
    }
}
