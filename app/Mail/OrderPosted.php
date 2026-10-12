<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "BW Castillejos posted an order": sent to the admins when a store posts. */
class OrderPosted extends Mailable implements ShouldQueue
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

        return new Envelope(subject: ($o->store?->name ?? 'A store').' posted an order · '.$o->number());
    }

    public function content(): Content
    {
        $o = $this->order();

        return new Content(view: 'mail.order-posted', with: [
            'order' => $o,
            'url' => url('/orders/'.$o->id),
        ]);
    }
}
