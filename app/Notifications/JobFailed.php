<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobFailed extends Notification
{
    public function __construct(private string $what, private string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->what} gagal — Clipper Studio")
            ->line("{$this->what} tidak selesai.")
            ->line("Penyebab: {$this->reason}")
            ->line('Render yang gagal tidak memotong kuota. Buka Clipper Studio dan coba lagi.')
            ->action('Buka Clipper Studio', url('/'));
    }
}
