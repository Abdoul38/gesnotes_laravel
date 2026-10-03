<!DOCTYPE html><html lang="fr"><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f5f5f5;padding:20px">
<div style="background:#1A3A5C;padding:20px;text-align:center;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:18px">{{ mb_strtoupper($entete[0]) }}</h2>
  <p style="color:#93c5fd;margin:4px 0 0;font-size:12px">{{ $entete[1] }}</p>
</div>
<div style="background:#fff;padding:24px;border-radius:0 0 8px 8px">
  <p style="color:#374151;font-size:13px">Cher(e) {{ $nom }},</p>
  <div style="color:#374151;font-size:13px;line-height:1.8">{!! nl2br(e($corps)) !!}</div>
  <hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0">
  <p style="color:#9ca3af;font-size:11px">Ce message vous est envoyé par le {{ $entete[1] }} — {{ $entete[0] }}.@if ($contact)<br>{{ $contact }}@endif</p>
</div>
</body></html>
