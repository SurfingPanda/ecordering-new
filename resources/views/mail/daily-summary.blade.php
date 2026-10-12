<x-mail.layout :logo="$message->embed(public_path('images/logo.png'))" :preheader="$s['ordered'].' of '.$s['total'].' stores ordered · '.$s['waiting'].' not yet · '.$s['units'].' units'" footer="You get this every day because you look after these stores in {{ config('app.name') }}.">
  <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#ff5000;font-weight:700;">Daily summary</div>
  <h1 style="margin:6px 0 4px;font-size:23px;line-height:30px;color:#0a2dbe;">{{ $day->format('l, M j, Y') }}</h1>
  <div style="font-size:14px;color:#5b607c;">{{ $scope }}</div>

  <p style="margin:18px 0 0;font-size:15px;line-height:23px;">Hi {{ $name }},
    @if ($s['total'] === 0)
      there are no active stores in your list today.
    @elseif ($s['waiting'] === 0)
      <strong>every one of your {{ $s['total'] }} {{ $s['total'] === 1 ? 'store' : 'stores' }} has ordered today.</strong>
    @else
      <strong>{{ $s['ordered'] }} of {{ $s['total'] }} stores have ordered today</strong>, and {{ $s['waiting'] }} {{ $s['waiting'] === 1 ? 'has' : 'have' }} not.
    @endif
  </p>

  {{-- the day at a glance --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 6px;">
    <tr>
      <td width="32%" align="center" style="background:#e8ecfb;border-radius:8px;padding:12px 6px;">
        <div style="font-size:26px;line-height:30px;font-weight:700;color:#0a2dbe;">{{ $s['ordered'] }}<span style="font-size:15px;color:#5b607c;">/{{ $s['total'] }}</span></div>
        <div style="font-size:12px;color:#5b607c;margin-top:2px;">stores ordered</div>
      </td>
      <td width="2%">&nbsp;</td>
      <td width="32%" align="center" style="background:#fff0e8;border-radius:8px;padding:12px 6px;">
        <div style="font-size:26px;line-height:30px;font-weight:700;color:#d94300;">{{ $s['waiting'] }}</div>
        <div style="font-size:12px;color:#5b607c;margin-top:2px;">not ordered</div>
      </td>
      <td width="2%">&nbsp;</td>
      <td width="32%" align="center" style="background:#f2f4f8;border-radius:8px;padding:12px 6px;">
        <div style="font-size:26px;line-height:30px;font-weight:700;color:#1b1f3b;">{{ number_format($s['units']) }}</div>
        <div style="font-size:12px;color:#5b607c;margin-top:2px;">units ordered</div>
      </td>
    </tr>
  </table>

  @if ($s['ecpos_problems'] > 0)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:14px 0 0;"><tr>
      <td style="background:#ffece6;border:1px solid #f0b8a6;border-left:4px solid #ff5000;border-radius:6px;padding:12px 14px;font-size:13px;line-height:20px;color:#7a2a00;">
        <strong>{{ $s['ecpos_problems'] }} {{ $s['ecpos_problems'] === 1 ? 'order has' : 'orders have' }} not reached ECPOS.</strong>
        Open <em>Orders (TRs)</em> to see which, and press Try again.
      </td>
    </tr></table>
  @endif

  @if ($s['waiting'] > 0)
    <h2 style="margin:24px 0 8px;font-size:15px;color:#b33b00;">Haven&rsquo;t ordered ({{ $s['waiting'] }})</h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
      @foreach ($s['waiting_stores'] as $w)
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #eceef6;font-weight:600;">{{ $w['name'] }} <span style="font-weight:400;color:#7b809b;font-size:12px;">{{ $w['code'] }}</span></td>
          <td align="right" style="padding:8px 0;border-bottom:1px solid #eceef6;font-size:12px;color:{{ $w['still_open'] ? '#1f3aa8' : '#b33b00' }};">
            {{ $w['still_open'] ? 'still open until '.\Carbon\Carbon::createFromFormat('H:i', $w['cutoff'])->format('g:i A') : 'cutoff passed ('.\Carbon\Carbon::createFromFormat('H:i', $w['cutoff'])->format('g:i A').')' }}
          </td>
        </tr>
      @endforeach
    </table>
  @endif

  @if ($s['ordered'] > 0)
    <h2 style="margin:24px 0 8px;font-size:15px;color:#0a2dbe;">Ordered ({{ $s['ordered'] }})</h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
      @foreach ($s['ordered_stores'] as $o)
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #eceef6;font-weight:600;">{{ $o['name'] }}<br><span style="font-weight:400;color:#7b809b;font-size:12px;">{{ $o['number'] }}</span></td>
          <td align="right" style="padding:8px 0;border-bottom:1px solid #eceef6;white-space:nowrap;">{{ number_format($o['units']) }} units<br><span style="color:#7b809b;font-size:12px;">{{ $o['items'] }} items</span></td>
        </tr>
      @endforeach
    </table>
  @endif

  @if ($s['cancelled'] > 0)
    <p style="margin:16px 0 0;font-size:13px;color:#5b607c;">{{ $s['cancelled'] }} {{ $s['cancelled'] === 1 ? 'order was' : 'orders were' }} cancelled today and {{ $s['cancelled'] === 1 ? 'is' : 'are' }} not counted above.</p>
  @endif

  <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin-top:26px;"><tr>
    <td align="center" style="background:#ff5000;border-radius:8px;"><a href="{{ $url }}" style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Open today&rsquo;s orders</a></td>
  </tr></table>
</x-mail.layout>
