<?php

namespace App\Notifications;

class SubmissionRejected extends AppNotification
{
    public function __construct(private string $reason, private string $submissionId)
    {
        parent::__construct();
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload(
            'alert',
            'Drop-off rejected',
            "Reason: {$this->reason}",
            'submission',
            $this->submissionId,
        );
    }
}
