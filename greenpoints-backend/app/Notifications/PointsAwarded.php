<?php

namespace App\Notifications;

class PointsAwarded extends AppNotification
{
    public function __construct(private int $points, private float $kg, private string $submissionId)
    {
        parent::__construct();
    }

    public function toArray(object $notifiable): array
    {
        if ($this->points > 0) {
            return $this->payload(
                'goal',
                "{$this->points} points have been added to your account!",
                "Your {$this->kg} kg drop-off was verified.",
                'submission',
                $this->submissionId,
            );
        }

        return $this->payload(
            'info',
            'Your drop-off was verified',
            "Thanks for recycling {$this->kg} kg! It was too light to earn a whole point this time.",
            'submission',
            $this->submissionId,
        );
    }
}
