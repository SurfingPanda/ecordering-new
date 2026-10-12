<x-mail.layout :logo="$message->embed(public_path('images/logo.png'))" preheader="Use this link to choose a new password" footer="You got this because someone asked to reset the password for this email address.">
  <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#ff5000;font-weight:700;">Password reset</div>
  <h1 style="margin:6px 0 14px;font-size:23px;line-height:30px;color:#0a2dbe;">Choose a new password</h1>

  <p style="margin:0 0 14px;font-size:15px;line-height:23px;">Hi {{ $name }},</p>
  <p style="margin:0 0 24px;font-size:15px;line-height:23px;">We got a request to reset the password for your {{ config('app.name') }} ordering account. Press the button to choose a new one.</p>

  <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 24px;"><tr>
    <td align="center" style="background:#ff5000;border-radius:8px;"><a href="{{ $url }}" style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Choose a new password</a></td>
  </tr></table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;"><tr>
    <td style="background:#e8ecfb;border-radius:6px;padding:12px 14px;font-size:13px;line-height:20px;color:#1f3aa8;">
      This link works for {{ $minutes }} minutes and can only be used once.
    </td>
  </tr></table>

  <p style="margin:0 0 14px;font-size:14px;line-height:21px;color:#5b607c;">If you did not ask for this, you can ignore this email: your password stays the same.</p>
  <p style="margin:0;font-size:12px;line-height:18px;color:#7b809b;word-break:break-all;">Button not working? Copy this address into your browser:<br>{{ $url }}</p>
</x-mail.layout>
