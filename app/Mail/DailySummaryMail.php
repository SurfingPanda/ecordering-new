<?php

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The end-of-day summary for one person: who ordered and who didn't, among the stores they look after. */
class DailySummaryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array $summary see DailySummary::build() */
    public function __construct(public string $name, public string $scope, public array $summary) {}

    public function envelope(): Envelope
    {
        $s = $this->summary;
        $day = CarbonImmutable::parse($s['date'])->format('D, M j');
        $tail = $s['waiting'] === 0 ? 'every store ordered' : $s['waiting'].' not ordered';

        return new Envelope(subject: "Daily summary {$day}: {$s['ordered']} of {$s['total']} stores ordered ({$tail})");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.daily-summary', with: [
            'name' => $this->name,
            'scope' => $this->scope,
            's' => $this->summary,
            'day' => CarbonImmutable::parse($this->summary['date']),
            'url' => url('/admin/today?date='.$this->summary['date']),
        ]);
    }
}
