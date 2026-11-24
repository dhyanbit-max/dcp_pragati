{{ __('Daily alert summary') }} · {{ now()->format('d M Y') }}

{{ __('New alerts in the last 24 hours') }}: {{ $newAlerts->count() }}
@foreach ($newAlerts->take(30) as $a)
- {{ $a->alert_code }} · {{ $a->work->code }} · {{ __('alert_types.'.$a->alert_code.'.title') }}
@endforeach

{{ __('Escalated to you') }}: {{ $escalated->count() }}
@foreach ($escalated->take(30) as $a)
- {{ $a->alert_code }} · {{ $a->work->code }} · {{ __('open since :date', ['date' => $a->opened_at->format('d M')]) }}
@endforeach

{{ __('Updates waiting for approval') }}: {{ $pendingApprovals }}

{{ __('Open your alerts') }}: {{ $url }}

-- 
DCP Pragati
