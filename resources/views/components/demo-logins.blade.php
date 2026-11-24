@props(['fill' => false])
{{-- Demo sign-ins seeded by DemoDataSeeder / DemoStatewideSeeder. Never shown in production, where they are not seeded. --}}
@unless (app()->isProduction())
    @php
        $main = [
            ['9000000001', __('State admin'), __('Everything')],
            ['9000000002', __('DPC (approver)'), __('Demo District A')],
            ['9000000003', __('DPC (approver)'), __('Demo District B')],
            ['9000000004', __('Co-district officer'), __('Demo Co-district A1')],
            ['9000000005', __('Field engineer (JE)'), __('3 works in Demo District A')],
            ['9000000006', __('Field engineer (JE)'), __('2 works in Demo District B')],
        ];
        $statewide = ['11' => 'Dhubri', '12' => 'Kokrajhar', '13' => 'Goalpara', '14' => 'Barpeta', '15' => 'Darrang', '16' => 'Sonitpur', '17' => 'Nagaon',
            '18' => 'Karbi Anglong', '19' => 'Golaghat', '20' => 'Lakhimpur', '21' => 'Dibrugarh', '22' => 'Tinsukia', '23' => 'Dima Hasao', '24' => 'Cachar'];
        $mobileCell = fn (string $m) => $fill
            ? '<button type="button" class="font-mono text-accent underline hover:no-underline" data-demo-mobile="'.$m.'">'.$m.'</button>'
            : '<span class="font-mono">'.$m.'</span>';
    @endphp
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-dashed border-line bg-surface-2 p-4 text-sm text-ink-2']) }}>
        <p class="font-semibold text-ink">{{ __('Demo sign-in') }}</p>
        <p class="mt-1">
            {{ __('This is a demo with made-up data. Every demo user has the password') }} <code class="rounded bg-surface px-1 font-mono text-ink">password</code>.
            @if (\App\Domain\Auth\DevOtp::enabled())
                {{ __('The one-time code is shown on the next screen; no SMS is sent.') }}
            @else
                {{ __('The one-time code is written to the server log; no SMS is sent.') }}
            @endif
            @if ($fill)
                {{ __('Click a mobile number to fill in the form.') }}
            @endif
        </p>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-xs sm:text-sm">
                <thead class="text-ink"><tr><th class="py-1 pr-3">{{ __('Mobile') }}</th><th class="py-1 pr-3">{{ __('Role') }}</th><th class="py-1">{{ __('Sees') }}</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($main as [$m, $role, $sees])
                        <tr><td class="py-1 pr-3">{!! $mobileCell($m) !!}</td><td class="py-1 pr-3">{{ $role }}</td><td class="py-1">{{ $sees }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <details class="mt-3">
            <summary class="cursor-pointer font-medium text-ink">{{ __('Statewide demo districts (14 more DPCs and engineers)') }}</summary>
            <p class="mt-2">{{ __('Each district has a DPC (90000011NN) and a field engineer (90000021NN). The district names are real but the data is made up.') }}</p>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-left text-xs sm:text-sm">
                    <thead class="text-ink"><tr><th class="py-1 pr-3">{{ __('District') }}</th><th class="py-1 pr-3">{{ __('DPC') }}</th><th class="py-1">{{ __('Field engineer') }}</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($statewide as $nn => $name)
                            <tr><td class="py-1 pr-3">{{ $name }} ({{ __('demo') }})</td><td class="py-1 pr-3">{!! $mobileCell("90000011$nn") !!}</td><td class="py-1">{!! $mobileCell("90000021$nn") !!}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
        <p class="mt-3 text-xs text-ink-3">{{ __('Field engineers report from the mobile app; on the web they see only the map. Demo grievances use mobiles 90000099NN.') }}</p>
        @if ($fill)
            <script>
                document.querySelectorAll('[data-demo-mobile]').forEach((b) => b.addEventListener('click', () => {
                    document.getElementById('mobile').value = b.dataset.demoMobile;
                    document.getElementById('password').value = 'password';
                    document.getElementById('password').form.querySelector('button:not([type=button])').focus();
                }));
            </script>
        @endif
    </div>
@endunless
