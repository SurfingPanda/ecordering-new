<x-mail.layout :logo="$message->embed(public_path('images/logo.png'))" :preheader="'Not sent to ECPOS: '.($order->store?->name).' · '.$order->number()">
  <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#b33b00;font-weight:700;">Needs attention</div>
  <h1 style="margin:6px 0 4px;font-size:23px;line-height:30px;color:#0a2dbe;">Order not sent to ECPOS</h1>
  <div style="font-size:14px;color:#5b607c;">{{ $order->store?->name }} &middot; {{ $order->number() }}</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 6px;"><tr>
    <td style="background:#ffece6;border:1px solid #f0b8a6;border-left:4px solid #ff5000;border-radius:6px;padding:14px 16px;font-size:14px;line-height:21px;color:#7a2a00;">
      <strong>Reason</strong><br>{{ $order->ecpos_error ?: 'ECPOS did not accept the order.' }}
    </td>
  </tr></table>

  <p style="margin:16px 0 4px;font-size:14px;line-height:22px;color:#1b1f3b;">
    @if ($order->ecpos_uncertain)
      <strong>Check ECPOS first.</strong> ECPOS did not confirm the last attempt, so it may already have this order. Sending it again could create it twice.
    @else
      ECPOS did not receive the order, and the order itself is posted and safe. Once the cause is fixed, open it and press <strong>Try again</strong>.
    @endif
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:14px 0 24px;font-size:14px;">
    <tr><td style="padding:10px 0;border-bottom:1px solid #eceef6;color:#7b809b;" width="38%">Store</td><td style="padding:10px 0;border-bottom:1px solid #eceef6;font-weight:600;">{{ $order->store?->name }} ({{ $order->store?->code }})</td></tr>
    <tr><td style="padding:10px 0;border-bottom:1px solid #eceef6;color:#7b809b;">Order date</td><td style="padding:10px 0;border-bottom:1px solid #eceef6;font-weight:600;">{{ $order->order_date->format('l, M j, Y') }}</td></tr>
    <tr><td style="padding:10px 0;color:#7b809b;">Size</td><td style="padding:10px 0;font-weight:600;">{{ (int) $order->lines_count }} items, {{ (int) $order->units }} units</td></tr>
  </table>

  <table role="presentation" cellpadding="0" cellspacing="0" align="center"><tr>
    <td align="center" style="background:#ff5000;border-radius:8px;"><a href="{{ $url }}" style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Open the order</a></td>
  </tr></table>
</x-mail.layout>
