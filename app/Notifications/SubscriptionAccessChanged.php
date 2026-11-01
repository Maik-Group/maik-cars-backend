<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SubscriptionAccessChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function via(object $notifiable): array
    {
        return $notifiable->fcm_token ? ['database', 'fcm'] : ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['type' => 'subscription_access_changed', 'title' => 'Subscription updated', 'body' => 'Your subscription access has changed. Open subscriptions to check the current status.', 'data' => ['type' => 'subscription_access_changed']];
    }

    public function toFcm(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
