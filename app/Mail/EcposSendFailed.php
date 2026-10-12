<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "An order did not reach ECPOS": sent to the admins when the automatic retries are used up (or ECPOS refused it). */
class EcposSendFailed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public int $orderId) {}

    private function order(): Order
    {
        return Order::withUnits()->with(['store:id,code,name', 'user:id,name'])->findOrFail($this->orderId);
    }

    public function envelope(): Envelope
    {
        $o = $this->order();

        return new Envelope(subject: 'Not sent to ECPOS: '.($o->store?->name ?? 'order').' · '.$o->number());
    }

    public function content(): Content
    {
        $o = $this->order();

        return new Content(view: 'mail.ecpos-failed', with: [
            'order' => $o,
            'url' => url('/orders/'.$o->id),
        ]);
    }
}
