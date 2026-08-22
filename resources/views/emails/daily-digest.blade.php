<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $platformName }} — Daily Digest for {{ $forDate }}</title>
</head>
{{--
    Sidebar Reorganization + Daily Digest session. Plain inline-styled HTML,
    not the admin panel's Tailwind CDN (mail clients strip <script>/external
    stylesheets, so that dependency can't travel into an email at all) —
    same slate/indigo palette as the admin panel's own header/badges for a
    consistent brand feel, just hand-written as inline `style=` attributes,
    the one approach every mail client actually renders reliably.
--}}
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Segoe UI, Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden; max-width:600px; width:100%;">
                    <tr>
                        <td style="background:#0f172a; padding:20px 28px;">
                            <div style="color:#ffffff; font-size:18px; font-weight:700;">{{ $platformName }}</div>
                            <div style="color:#94a3b8; font-size:13px; margin-top:2px;">Daily Digest — {{ $forDate }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 28px;">
                            <div style="font-size:14px; color:#475569; margin-bottom:16px;">
                                Hi {{ $recipient->name }}, here's what happened
                                {{ $insights !== null ? 'and what needs attention' : '' }} today.
                            </div>

                            {{-- KPI summary --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                                <tr>
                                    <td width="50%" style="padding:10px; background:#f8fafc; border-radius:6px;">
                                        <div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b;">Bookings Today</div>
                                        <div style="font-size:22px; font-weight:700; color:#0f172a;">{{ $kpis['bookings_today'] }}</div>
                                        <div style="font-size:12px; color:#94a3b8;">Yesterday: {{ $kpis['bookings_yesterday'] }}</div>
                                    </td>
                                    <td width="12"></td>
                                    <td width="50%" style="padding:10px; background:#f8fafc; border-radius:6px;">
                                        <div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b;">Completion Rate</div>
                                        <div style="font-size:22px; font-weight:700; color:#0f172a;">{{ $kpis['completion_rate'] !== null ? $kpis['completion_rate'].'%' : '—' }}</div>
                                        <div style="font-size:12px; color:#94a3b8;">Completed: {{ $kpis['completed_today'] }}</div>
                                    </td>
                                </tr>
                                <tr><td colspan="3" height="12"></td></tr>
                                <tr>
                                    <td width="50%" style="padding:10px; background:#f8fafc; border-radius:6px;">
                                        <div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b;">Revenue Today</div>
                                        <div style="font-size:22px; font-weight:700; color:#0f172a;">{{ $currencySymbol }}{{ number_format($kpis['revenue_today'], 2) }}</div>
                                    </td>
                                    <td width="12"></td>
                                    <td width="50%" style="padding:10px; background:#f8fafc; border-radius:6px;">
                                        <div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b;">Providers Online</div>
                                        <div style="font-size:22px; font-weight:700; color:#0f172a;">{{ $kpis['providers_online'] }}</div>
                                        <div style="font-size:12px; color:#94a3b8;">of {{ $kpis['providers_total'] }} total</div>
                                    </td>
                                </tr>
                            </table>

                            {{-- Insights — same detectors as Dashboard's "Daily Insights" panel --}}
                            @if ($insights !== null)
                                @php
                                    $insightCount = $insights['stuck_bookings']->count() + $insights['provider_anomalies']->count() + $insights['zone_coverage']->count();
                                @endphp

                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:8px;">
                                    Needs Attention ({{ $insightCount }})
                                </div>

                                @if ($insightCount === 0)
                                    <div style="font-size:13px; color:#16a34a; background:#f0fdf4; border-radius:6px; padding:10px 12px; margin-bottom:8px;">
                                        No stuck bookings, provider anomalies, or zone coverage gaps right now.
                                    </div>
                                @else
                                    @foreach ($insights['stuck_bookings'] as $row)
                                        <div style="font-size:13px; color:#92400e; background:#fffbeb; border-radius:6px; padding:10px 12px; margin-bottom:6px;">
                                            Booking <strong>{{ $row['booking']->code }}</strong> has been in
                                            "{{ str_replace('_', ' ', $row['status']) }}" for {{ $row['minutes_stuck'] }} min
                                            (threshold {{ $row['threshold_minutes'] }}).
                                        </div>
                                    @endforeach
                                    @foreach ($insights['provider_anomalies'] as $row)
                                        <div style="font-size:13px; color:#9a3412; background:#fff7ed; border-radius:6px; padding:10px 12px; margin-bottom:6px;">
                                            Provider <strong>{{ $row['provider']->user->name ?? ('#'.$row['provider']->id) }}</strong>
                                            is {{ $row['label'] }} at {{ $row['today_rate'] }}% today vs their {{ $row['baseline_rate'] }}% average.
                                        </div>
                                    @endforeach
                                    @foreach ($insights['zone_coverage'] as $row)
                                        <div style="font-size:13px; color:#991b1b; background:#fef2f2; border-radius:6px; padding:10px 12px; margin-bottom:6px;">
                                            Zone <strong>{{ $row['zone']->display_name }}</strong> has only {{ $row['online_providers'] }}
                                            online provider(s) (minimum expected: {{ $row['threshold'] }}).
                                        </div>
                                    @endforeach
                                @endif
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px; border-top:1px solid #e2e8f0;">
                            <div style="font-size:11px; color:#94a3b8;">
                                Automated daily digest from {{ $platformName }}. Figures reflect your own scope only — the same data your Dashboard shows you.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
