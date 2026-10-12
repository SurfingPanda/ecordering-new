@props(['logo', 'preheader' => '', 'footer' => null])
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#fffaf3;">
{{-- the line some mail apps show beside the subject --}}
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#fffaf3;">{{ $preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fffaf3;">
<tr><td align="center" style="padding:28px 12px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;font-family:'Segoe UI',-apple-system,Roboto,Helvetica,Arial,sans-serif;color:#1b1f3b;">

    {{-- brand header --}}
    <tr><td align="center" style="background:#0a2dbe;border-radius:10px 10px 0 0;padding:26px 24px 22px;">
      <img src="{{ $logo }}" width="84" height="84" alt="{{ config('app.name') }}" style="display:block;border:0;margin:0 auto;">
      <div style="margin-top:12px;font-size:13px;letter-spacing:2px;text-transform:uppercase;color:#cfd8ff;font-weight:600;">Ordering System</div>
    </td></tr>
    <tr><td style="background:#ff5000;height:4px;line-height:4px;font-size:0;">&nbsp;</td></tr>

    {{-- content --}}
    <tr><td style="background:#ffffff;border:1px solid #e3e6f2;border-top:0;border-radius:0 0 10px 10px;padding:30px 30px 26px;">
      {{ $slot }}
    </td></tr>

    {{-- footer --}}
    <tr><td align="center" style="padding:18px 16px 0;font-size:12px;line-height:18px;color:#7b809b;">
      {{ $footer ?? 'You get this because you are on the admin email list for '.config('app.name').' ordering.' }}<br>
      &copy; {{ date('Y') }} {{ config('app.name') }}
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
