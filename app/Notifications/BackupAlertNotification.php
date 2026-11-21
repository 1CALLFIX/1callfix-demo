<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Off-site backup alert (backups:check-offsite): "failing" while copies stop reaching the Drive, "recovered" once
 * after it is fixed. Email + push to admins who can open Backup & Restore (AdminOpsAlertService::backupAlert()).
 * Added 2026-11-21 after the Drive filled up on 29 Oct and the backup failed for three weeks with nobody told.
 */
class BackupAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $problems  plain sentences, e.g. "No new hourly copy since 21/11/2026, 08:15." */
    public function __construct(
        public readonly string $event,
        public readonly array $problems,
        public readonly ?string $reason,
        private array $channels,
    ) {}

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "admin.ops_backup_{$this->event}";
    }

    public function pushLink($notifiable): string
    {
        return route('admin.operations.backup-restore');
    }

    public function toPush($notifiable): array
    {
        if ($this->event === 'recovered') {
            return ['title' => 'Backup is working again', 'body' => implode(' ', $this->problems)];
        }

        return [
            'title' => 'Off-site backup is failing',
            'body' => trim(implode(' ', $this->problems).' '.($this->reason ?? '')),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('[1CallFix Admin] '.$this->toPush($notifiable)['title']);

        foreach ($this->problems as $line) {
            $mail->line($line);
        }

        if ($this->event !== 'recovered') {
            if ($this->reason) {
                $mail->line($this->reason);
            }
            $mail->line('Until this is fixed, a lost or broken server would also lose everything since the last copy.');
        }

        return $mail->action('Open Backup & Restore', $this->pushLink($notifiable));
    }
}
