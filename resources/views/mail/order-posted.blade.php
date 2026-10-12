<x-mail.layout :logo="$message->embed(public_path('images/logo.png'))" :preheader="($order->store?->name).' · '.(int) $order->units.' units in '.(int) $order->lines_count.' items'">
  <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#ff5000;font-weight:700;">New order posted</div>
  <h1 style="margin:6px 0 4px;font-size:23px;line-height:30px;color:#0a2dbe;">{{ $order->store?->name }}</h1>
  <div style="font-size:14px;color:#5b607c;">{{ $order->number() }} &middot; {{ $order->store?->code }}</div>

  {{-- the size of the order at a glance --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:22px 0 6px;">
    <tr>
      <td width="49%" align="center" style="background:#e8ecfb;border-radius:8px;padding:14px 8px;">
        <div style="font-size:28px;line-height:32px;font-weight:700;color:#0a2dbe;">{{ (int) $order->lines_count }}</div>
        <div style="font-size:12px;color:#5b607c;margin-top:2px;">items</div>
      </td>
      <td width="2%">&nbsp;</td>
      <td width="49%" align="center" style="background:#fff0e8;border-radius:8px;padding:14px 8px;">
        <div style="font-size:28px;line-height:32px;font-weight:700;color:#d94300;">{{ (int) $order->units }}</div>
        <div style="font-size:12px;color:#5b607c;margin-top:2px;">units</div>
      </td>
    </tr>
  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:14px 0 24px;font-size:14px;">
    <tr><td style="padding:10px 0;border-bottom:1px solid #eceef6;color:#7b809b;" width="38%">Order date</td><td style="padding:10px 0;border-bottom:1px solid #eceef6;font-weight:600;">{{ $order->order_date->format('l, M j, Y') }}</td></tr>
    <tr><td style="padding:10px 0;border-bottom:1px solid #eceef6;color:#7b809b;">Ordered by</td><td style="padding:10px 0;border-bottom:1px solid #eceef6;font-weight:600;">{{ $order->user?->name }}</td></tr>
    <tr><td style="padding:10px 0;color:#7b809b;">Reference</td><td style="padding:10px 0;font-weight:600;">{{ $order->reference() }}</td></tr>
  </table>

  <table role="presentation" cellpadding="0" cellspacing="0" align="center"><tr>
    <td align="center" style="background:#ff5000;border-radius:8px;"><a href="{{ $url }}" style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Open the order</a></td>
  </tr></table>
</x-mail.layout>
