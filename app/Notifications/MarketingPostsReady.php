<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "3 posts are ready to share today" (marketing:posts-due, owner 2026-11-21). Email + push to the marketing team. */
class MarketingPostsReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $count, public readonly bool $evening, private array $channels) {}

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return 'admin.marketing_posts_ready';
    }

    public function pushLink($notifiable): string
    {
        return route('admin.marketing.posts');
    }

    public function toPush($notifiable): array
    {
        $posts = $this->count === 1 ? '1 post is' : "{$this->count} posts are";

        return $this->evening
            ? ['title' => 'Posts still waiting', 'body' => "{$posts} still not shared today. Open Social posts and tap Share."]
            : ['title' => "Today's posts are ready", 'body' => "{$posts} ready to share on Facebook, Instagram, Google and WhatsApp. One tap each."];
    }

    public function toMail($notifiable): MailMessage
    {
        $copy = $this->toPush($notifiable);

        return (new MailMessage)->subject('[1CallFix Marketing] '.$copy['title'])->line($copy['body'])
            ->action('Open Social posts', $this->pushLink($notifiable));
    }
}
