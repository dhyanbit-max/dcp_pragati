@php
    $sections = [
        'start' => __('Getting started'),
        ...(app()->isProduction() ? [] : ['demo' => __('Demo sign-in')]),
        'citizens' => __('For citizens'),
        'field' => __('Field staff: the mobile app'),
        'dpc' => __('District Planning Cell: approvals'),
        'entry' => __('Web data entry'),
        'monitor' => __('Dashboard, works and map'),
        'alerts' => __('Alerts'),
        'reports' => __('Reports and AQ desk'),
        'rules' => __('The twelve checks'),
        'faq' => __('Common questions'),
    ];
    $rules = [
        'R01' => __('Spending is more than the amount released.'),
        'R02' => __('Progress is lower than the last approved progress.'),
        'R03' => __('Photos were taken too far from the work site.'),
        'R04' => __('Too few photos for a change in progress.'),
        'R05' => __('Photos are too old or have a wrong date.'),
        'R06' => __('The same photo was used in an earlier update.'),
        'R07' => __('Spending is far ahead of physical progress.'),
        'R08' => __('The progress % does not match the selected stage.'),
        'R09' => __('Spending keeps rising with no physical progress.'),
        'R10' => __('Photo location is not reliable (poor GPS or mock location).'),
        'R11' => __('Spending is more than the sanctioned amount.'),
        'R12' => __('Photos were uploaded as files, not taken in the app.'),
    ];
    $alerts = [
        'A01' => [__('No recent progress update'), __('The work has had no approved update for too long.')],
        'A02' => [__('Milestone missed'), __('A planned stage date has passed and the stage is not reached.')],
        'A03' => [__('Released funds not spent'), __('Only a small part of the released money has been spent.')],
        'A04' => [__('Spending ahead of progress'), __('Spending is far ahead of physical progress.')],
        'A05' => [__('Approval overdue'), __('Updates have been waiting too long for the DPC to approve.')],
    ];
@endphp
<x-layouts.public :title="__('User manual')">
    <section class="border-b border-line bg-gradient-to-b from-accent-soft to-surface">
        <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
            <p class="text-sm font-medium text-accent">{{ __('Help') }}</p>
            <h1 class="mt-1 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ __('User manual') }}</h1>
            <p class="mt-3 max-w-2xl text-ink-2">{{ __('How to use DCP Pragati: for citizens, field engineers, district officers and state officers. Pick the part that matches your role.') }}</p>
        </div>
    </section>

    <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[14rem_1fr]">
        <nav aria-label="{{ __('Contents') }}" class="lg:sticky lg:top-24 lg:self-start">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-ink-3">{{ __('Contents') }}</h2>
            <ol class="mt-2 space-y-1 text-sm">
                @foreach ($sections as $id => $label)
                    <li><a href="#{{ $id }}" class="block rounded px-2 py-1 text-ink-2 hover:bg-surface-2 hover:text-accent">{{ $label }}</a></li>
                @endforeach
            </ol>
        </nav>

        <div class="min-w-0 space-y-12 text-ink-2 [&_h2]:scroll-mt-24 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-ink [&_h3]:mt-5 [&_h3]:font-semibold [&_h3]:text-ink [&_li]:mt-1 [&_ol]:mt-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mt-2 [&_ul]:mt-2 [&_ul]:list-disc [&_ul]:pl-5">

            <section aria-labelledby="start">
                <h2 id="start">{{ $sections['start'] }}</h2>
                <p>{{ __('DCP Pragati shows the progress of works under the Decentralised Planning schemes of Assam. Engineers report progress from the site, the system checks each update, and the District Planning Cell approves it. Only approved figures appear on dashboards and reports. e-Rupantor remains the official record.') }}</p>
                <h3>{{ __('Signing in (officers)') }}</h3>
                <ol>
                    <li>{!! __('Open :link and enter your mobile number and password.', ['link' => '<a class="text-accent underline" href="'.route('login').'">'.e(__('Staff sign in')).'</a>']) !!}</li>
                    <li>{{ __('Enter the one-time code (OTP) sent to your mobile.') }}</li>
                    <li>{{ __('You land on your dashboard. You only see works in your own district, co-district or agency.') }}</li>
                </ol>
                <p>{!! __('First time, or forgot your password? Use :link. A code is sent to your registered mobile number.', ['link' => '<a class="text-accent underline" href="'.route('password.setup').'">'.e(__('Set or reset your password')).'</a>']) !!}</p>
                <x-demo-logins id="demo" class="mt-4 scroll-mt-24" />
                <h3>{{ __('Language') }}</h3>
                <p>{{ __('Choose English, Assamese or Bengali from the language menu at the top of any page.') }}</p>
            </section>

            <section aria-labelledby="citizens">
                <h2 id="citizens">{{ $sections['citizens'] }}</h2>
                <p>{{ __('You do not need an account. The home page shows statewide totals from approved updates.') }}</p>
                @if (config('dcp.public.grievances'))
                    <h3>{{ __('Raise a grievance') }}</h3>
                    <ol>
                        <li>{!! __('Open :link.', ['link' => '<a class="text-accent underline" href="'.route('grievances.create').'">'.e(__('Raise a grievance')).'</a>']) !!}</li>
                        <li>{{ __('Choose the district and describe the problem: poor quality, work left half-done, a safety hazard, or a work that never started.') }}</li>
                        <li>{{ __('Add a photo if you have one. Enter your mobile number and confirm it with the code sent by SMS. Your name is optional.') }}</li>
                        <li>{{ __('Note the reference number shown at the end, for example GRV-26-000123.') }}</li>
                    </ol>
                    <p>{{ __('The district office acknowledges it within :a days and answers within :r days. You get SMS updates until it is closed.', ['a' => config('dcp.grievances.acknowledge_days'), 'r' => config('dcp.grievances.resolve_days')]) }}</p>
                    <h3>{{ __('Track a grievance') }}</h3>
                    <p>{!! __('Open :link, enter the reference number and the mobile number you used.', ['link' => '<a class="text-accent underline" href="'.route('grievances.track').'">'.e(__('Track a grievance')).'</a>']) !!}</p>
                @endif
            </section>

            <section aria-labelledby="field">
                <h2 id="field">{{ $sections['field'] }}</h2>
                <p>{{ __('Junior engineers and site staff report progress with the DCP Pragati Android app. It works without signal and sends updates when the phone is back online.') }}</p>
                <h3>{{ __('First use') }}</h3>
                <ol>
                    <li>{{ __('Install the app from the link or QR code given by your district office.') }}</li>
                    <li>{{ __('Pick your language, enter your mobile number and the OTP.') }}</li>
                    <li>{{ __('Allow camera and location access. The app needs both to take site photos.') }}</li>
                </ol>
                <h3>{{ __('Updating progress') }}</h3>
                <ol>
                    <li>{{ __('Open My works and tap the work. Works due for an update are marked.') }}</li>
                    <li>{{ __('Tap Update progress. Choose the current stage and set the progress %. It cannot be lower than the last approved figure.') }}</li>
                    <li>{{ __('Enter the total amount spent so far (cumulative, not just since the last update).') }}</li>
                    <li>{{ __('Take photos at the site with the in-app camera. Stand at the work; photos taken elsewhere are flagged.') }}</li>
                    <li>{{ __('Add remarks if needed and tap Submit.') }}</li>
                </ol>
                <p>{{ __('If the app shows a red error, fix it before submitting. Yellow warnings can be submitted; the DPC will look at them closely.') }}</p>
                <h3>{{ __('Outbox') }}</h3>
                <p>{{ __('The Outbox shows every update: waiting to send, sent, approved, returned or rejected. A returned update shows the DPC\'s note. Correct it and submit again. Do not uninstall the app while updates are waiting to send.') }}</p>
            </section>

            <section aria-labelledby="dpc">
                <h2 id="dpc">{{ $sections['dpc'] }}</h2>
                <p>{{ __('Nothing counts on dashboards, alerts, reports or e-Rupantor until the DPC approves it.') }}</p>
                <ol>
                    <li>{{ __('Open Approvals. Updates are split into Flagged (one or more checks failed) and Clean.') }}</li>
                    <li>{{ __('For each update, compare old and new stage, progress and spending, and look at the photos and their location on the map.') }}</li>
                    <li>{{ __('Approve, Return (sends it back to the engineer with your note), or Reject. A note is required to return, reject, or approve a flagged update.') }}</li>
                    <li>{{ __('Clean updates can be selected and approved together.') }}</li>
                </ol>
                <p>{{ __('Approve updates promptly. Updates that wait too long raise an Approval overdue alert.') }}</p>
            </section>

            <section aria-labelledby="entry">
                <h2 id="entry">{{ $sections['entry'] }}</h2>
                <ul>
                    <li><strong class="text-ink">{{ __('Web update') }}:</strong> {{ __('open a work and choose Update progress. Same fields and checks as the app. Uploaded photo files are always flagged for the DPC.') }}</li>
                    <li><strong class="text-ink">{{ __('Bulk edit') }}:</strong> {{ __('a grid of works in your area. Change stage, % or spending on several rows; each changed row becomes one update and is checked.') }}</li>
                    <li><strong class="text-ink">{{ __('Fund releases') }}:</strong> {{ __('record each release on the work page with amount, date and order number.') }}</li>
                    <li><strong class="text-ink">{{ __('Import') }}:</strong> {{ __('administrators can load works and releases from Excel. Download the template from the Import page first.') }}</li>
                </ul>
            </section>

            <section aria-labelledby="monitor">
                <h2 id="monitor">{{ $sections['monitor'] }}</h2>
                <ul>
                    <li><strong class="text-ink">{{ __('Dashboard') }}:</strong> {{ __('totals for your area, with filters for year, scheme, fund source, agency and status. Click a district or co-district row to drill down.') }}</li>
                    <li><strong class="text-ink">{{ __('Exception lists') }}:</strong> {{ __('delayed, stalled, spending ahead of progress, no recent update, and approvals pending.') }}</li>
                    <li><strong class="text-ink">{{ __('Works') }}:</strong> {{ __('search and open any work to see its full record: money sanctioned, released and spent, a progress chart, photos, milestones and the history of every update.') }}</li>
                    <li><strong class="text-ink">{{ __('Map') }}:</strong> {{ __('every work with a known site, coloured by status, with district and co-district boundaries.') }}</li>
                </ul>
            </section>

            <section aria-labelledby="alerts">
                <h2 id="alerts">{{ $sections['alerts'] }}</h2>
                <p>{{ __('The system raises alerts automatically and sends SMS to the people responsible. Open Alerts to see yours, acknowledge them with a note, and resolve them. Alerts that stay open are escalated to senior officers.') }}</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[28rem] text-left text-sm">
                        <thead class="border-b border-line text-ink"><tr><th class="py-2 pr-3">{{ __('Code') }}</th><th class="py-2 pr-3">{{ __('Alert') }}</th><th class="py-2">{{ __('Meaning') }}</th></tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($alerts as $code => [$title, $meaning])
                                <tr><td class="py-2 pr-3 font-mono text-xs">{{ $code }}</td><td class="py-2 pr-3 text-ink">{{ $title }}</td><td class="py-2">{{ $meaning }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section aria-labelledby="reports">
                <h2 id="reports">{{ $sections['reports'] }}</h2>
                <p>{{ __('Open Reports, pick a report, set the filters and run it. Download the result as Excel or PDF. Reports use approved figures only and cover your own area.') }}</p>
                <p>{{ __('The Assembly Question desk prepares a reply with current approved figures: enter the question number, session and member, choose the constituencies, scheme and year it covers, and download it as PDF or Excel.') }}</p>
            </section>

            <section aria-labelledby="rules">
                <h2 id="rules">{{ $sections['rules'] }}</h2>
                <p>{{ __('Every update, from the app or the web, goes through these checks. Some stop the update; others flag it for the DPC.') }}</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[24rem] text-left text-sm">
                        <thead class="border-b border-line text-ink"><tr><th class="py-2 pr-3">{{ __('Code') }}</th><th class="py-2">{{ __('What it checks') }}</th></tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($rules as $code => $text)
                                <tr><td class="py-2 pr-3 font-mono text-xs">{{ $code }}</td><td class="py-2">{{ $text }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section aria-labelledby="faq">
                <h2 id="faq">{{ $sections['faq'] }}</h2>
                <div class="mt-3 space-y-2">
                    @foreach ([
                        [__('I did not get the OTP.'), __('Wait a minute and try again. Check that your mobile number is the one registered with your district office. If it still fails, contact the district office.')],
                        [__('I cannot see a work I am responsible for.'), __('Works are shown by assignment and jurisdiction. Ask your DPC to assign the work to you.')],
                        [__('My update was rejected because progress went down.'), __('Progress can never be lower than the last approved figure. Enter the same or a higher %.')],
                        [__('Why do the figures differ from e-Rupantor?'), __('DCP Pragati shows approved site updates as soon as they are approved. e-Rupantor is updated from them and remains the official record.')],
                        [__('There was no signal at the site.'), __('Submit anyway. The update waits in the Outbox and is sent automatically when the phone is online.')],
                    ] as [$q, $a])
                        <details class="card p-4">
                            <summary class="cursor-pointer font-medium text-ink">{{ $q }}</summary>
                            <p>{{ $a }}</p>
                        </details>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</x-layouts.public>
