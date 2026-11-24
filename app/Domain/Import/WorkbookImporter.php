<?php

namespace App\Domain\Import;

use App\Domain\Access\JurisdictionScope;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\CoDistrict;
use App\Models\Constituency;
use App\Models\District;
use App\Models\FinancialYear;
use App\Models\FundRelease;
use App\Models\FundSource;
use App\Models\ImplementingAgency;
use App\Models\SchemeType;
use App\Models\StageTemplate;
use App\Models\SyncBatch;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkAssignment;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports the template workbook (Districts, CoDistricts, Constituencies, Users, Works, Releases). One code path for
 * preview and import: a preview runs everything inside a transaction and rolls it back.
 * Each row runs in its own savepoint, so a bad row is skipped and reported, never half-saved.
 * Works are matched by e-Rupantor ID, then by code; IDs are never renumbered (docs/11 §3).
 */
class WorkbookImporter
{
    private User $user;

    /** @var list<int>|null null = any district */
    private ?array $allowedDistricts;

    private ImportReport $report;

    /** @param array<string, list<array{0:int, 1:array}>> $sheets */
    public function run(array $sheets, User $user, bool $commit, ?string $fileName = null): ImportReport
    {
        $this->user = $user;
        $this->report = new ImportReport;
        $this->allowedDistricts = $user->hasStateScope()
            ? null
            : $user->jurisdictions()->where('level', 'district')->pluck('district_id')->all();

        if ($sheets === []) {
            $this->report->error('File', 0, 'No sheets named Districts, CoDistricts, Constituencies, Users, Works or Releases were found.');

            return $this->report;
        }

        DB::beginTransaction();
        try {
            foreach (array_keys(Template::SHEETS) as $sheet) {
                if (! isset($sheets[$sheet])) {
                    continue;
                }
                if (in_array($sheet, ['Districts', 'CoDistricts', 'Constituencies'], true) && $this->allowedDistricts !== null) {
                    $this->report->error($sheet, 0, 'Only state administrators can import districts, co-districts and constituencies. Sheet skipped.');

                    continue;
                }
                if ($sheet === 'Users' && ! $user->can(Permission::MANAGE_USERS)) {
                    $this->report->error($sheet, 0, 'You do not have permission to manage users. Sheet skipped.');

                    continue;
                }
                if ($missing = array_diff(Template::required($sheet), array_keys($sheets[$sheet][0][1] ?? []))) {
                    $this->report->error($sheet, 1, 'Missing required columns: '.implode(', ', $missing).'. Sheet skipped.');

                    continue;
                }
                foreach ($sheets[$sheet] as [$rowNo, $row]) {
                    $this->row($sheet, $rowNo, $row);
                }
            }

            if ($commit) {
                $this->recordBatch($fileName);
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->report;
    }

    private function row(string $sheet, int $rowNo, array $row): void
    {
        try {
            $outcome = DB::transaction(fn () => match ($sheet) {
                'Districts' => $this->district($row),
                'CoDistricts' => $this->coDistrict($row),
                'Constituencies' => $this->constituency($row),
                'Users' => $this->importUser($row, $sheet, $rowNo),
                'Works' => $this->work($row, $sheet, $rowNo),
                'Releases' => $this->release($row),
            });
            $this->report->count($sheet, $outcome);
        } catch (RowError $e) {
            $this->report->count($sheet, 'failed');
            $this->report->error($sheet, $rowNo, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->report->count($sheet, 'failed');
            $this->report->error($sheet, $rowNo, 'Could not save this row: '.class_basename($e));
        }
    }

    private function district(array $r): string
    {
        $d = District::firstOrNew(['lgd_code' => $this->req($r, 'lgd_code')]);
        $d->fill([
            'name_en' => $this->req($r, 'name_en'),
            'name_as' => $r['name_as'] ?? $d->name_as,
            'name_bn' => $r['name_bn'] ?? $d->name_bn,
            'erupantor_code' => $r['erupantor_code'] ?? $d->erupantor_code,
            'is_pilot' => isset($r['is_pilot']) ? $this->bool($r['is_pilot']) : (bool) $d->is_pilot,
        ]);

        return $this->save($d);
    }

    private function coDistrict(array $r): string
    {
        $district = District::where('lgd_code', $this->req($r, 'district_lgd_code'))->first()
            ?? throw new RowError("District with LGD code {$r['district_lgd_code']} not found.");

        $c = CoDistrict::firstOrNew(['lgd_code' => $this->req($r, 'lgd_code')]);
        $c->fill([
            'district_id' => $district->id,
            'name_en' => $this->req($r, 'name_en'),
            'name_as' => $r['name_as'] ?? $c->name_as,
            'name_bn' => $r['name_bn'] ?? $c->name_bn,
            'erupantor_code' => $r['erupantor_code'] ?? $c->erupantor_code,
            'is_pilot' => isset($r['is_pilot']) ? $this->bool($r['is_pilot']) : (bool) $c->is_pilot,
        ]);

        return $this->save($c);
    }

    private function constituency(array $r): string
    {
        $acNo = $this->req($r, 'ac_no');
        if (! ctype_digit($acNo) || (int) $acNo < 1 || (int) $acNo > 32767) {
            throw new RowError("ac_no '{$acNo}' must be a whole number.");
        }
        $district = District::where('lgd_code', $this->req($r, 'district_lgd_code'))->first()
            ?? throw new RowError("District with LGD code {$r['district_lgd_code']} not found.");

        $c = Constituency::firstOrNew(['ac_no' => (int) $acNo]);
        $c->fill([
            'district_id' => $district->id,
            'name_en' => $this->req($r, 'name_en'),
            'name_as' => $r['name_as'] ?? $c->name_as,
            'name_bn' => $r['name_bn'] ?? $c->name_bn,
            'parliamentary_constituency' => $r['parliamentary_constituency'] ?? $c->parliamentary_constituency,
        ]);

        return $this->save($c);
    }

    /** Roles a district-level importer (DPC) may create or change. State users may import any role but system_admin. */
    private const DISTRICT_IMPORTABLE_ROLES = [Role::CO_DISTRICT_OFFICER, Role::AGENCY_USER, Role::FIELD_USER];

    /** Roles that sign in to the web (password + OTP); field users use the app only. */
    private const WEB_ROLES = [Role::STATE_ADMIN, Role::STATE_VIEWER, Role::DISTRICT_COLLECTOR, Role::DPC, Role::CO_DISTRICT_OFFICER, Role::AGENCY_USER];

    /**
     * Users sheet (docs/18 D-6). Matched on mobile. The row's role and jurisdiction replace the
     * user's current ones. Passwords are never imported.
     */
    private function importUser(array $r, string $sheet, int $rowNo): string
    {
        $mobile = $this->mobile($this->req($r, 'mobile'));
        $role = strtolower($this->req($r, 'role'));
        $allowedRoles = $this->allowedDistricts === null
            ? array_values(array_diff(Role::ALL, [Role::SYSTEM_ADMIN]))
            : self::DISTRICT_IMPORTABLE_ROLES;
        if (! in_array($role, Role::ALL, true)) {
            throw new RowError("Unknown role '{$role}'. Use one of: ".implode(', ', $allowedRoles).'.');
        }
        if (! in_array($role, $allowedRoles, true)) {
            throw new RowError("You cannot import users with role {$role}.");
        }
        if ($mobile === $this->user->mobile) {
            throw new RowError('You cannot change your own account by import.');
        }

        $jurisdiction = $this->jurisdictionFor($role, $r);

        $user = User::where('mobile', $mobile)->first();
        if ($user) {
            $this->assertCanManageUser($user, $allowedRoles);
        }

        $email = isset($r['email']) && $r['email'] !== '' ? strtolower(trim((string) $r['email'])) : null;
        if ($email !== null) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RowError("email '{$email}' is not a valid address.");
            }
            if (User::where('email', $email)->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists()) {
                throw new RowError("email {$email} is already used by another user.");
            }
        }
        $locale = strtolower((string) ($r['preferred_locale'] ?? ''));
        if ($locale !== '' && ! in_array($locale, ['en', 'as', 'bn'], true)) {
            throw new RowError("preferred_locale '{$locale}' must be en, as or bn.");
        }

        $isNew = ! $user;
        $user ??= new User(['mobile' => $mobile]);
        $user->fill([
            'name' => $this->req($r, 'name'),
            'designation' => $r['designation'] ?? $user->designation,
            'email' => $email ?? $user->email,
            'preferred_locale' => $locale ?: ($user->preferred_locale ?? 'en'),
            'is_active' => isset($r['is_active']) && $r['is_active'] !== '' ? $this->bool($r['is_active']) : ($user->is_active ?? true),
        ]);
        $outcome = $this->save($user);

        $oldRoles = $isNew ? [] : $user->getRoleNames()->sort()->values()->all();
        $oldJurisdictions = $isNew ? [] : $this->jurisdictionRows($user);
        $user->syncRoles([$role]);
        $user->jurisdictions()->delete();
        if ($jurisdiction) {
            $user->jurisdictions()->create($jurisdiction);
        }
        $newJurisdictions = $this->jurisdictionRows($user);

        // Roles and jurisdictions are not covered by the model's own activity log, so record them here.
        if ($oldRoles !== [$role] || $oldJurisdictions !== $newJurisdictions) {
            activity()->performedOn($user)->causedBy($this->user)->event('access_changed')
                ->withProperties([
                    'source' => 'import',
                    'old' => ['roles' => $oldRoles, 'jurisdictions' => $oldJurisdictions],
                    'attributes' => ['roles' => [$role], 'jurisdictions' => $newJurisdictions],
                ])
                ->log('Role and jurisdiction set by import');
            $outcome = $outcome === 'skipped' ? 'updated' : $outcome;
        }

        if ($isNew && in_array($role, self::WEB_ROLES, true)) {
            $this->report->warning($sheet, $rowNo, "{$mobile}: no password is set. They set one from 'First time here, or forgot your password?' on the sign-in page.");
        }

        return $outcome;
    }

    /** @return array<string, int|string>|null the user_jurisdictions row for this role, or null for state and field roles */
    private function jurisdictionFor(string $role, array $r): ?array
    {
        $district = function () use ($r): District {
            $d = District::where('lgd_code', $this->req($r, 'district_lgd_code'))->first()
                ?? throw new RowError("District with LGD code {$r['district_lgd_code']} not found.");
            $this->assertDistrict($d->id, 'user');

            return $d;
        };

        switch ($role) {
            case Role::DISTRICT_COLLECTOR:
            case Role::DPC:
                return ['level' => 'district', 'district_id' => $district()->id];
            case Role::CO_DISTRICT_OFFICER:
                $c = CoDistrict::where('lgd_code', $this->req($r, 'co_district_lgd_code'))->first()
                    ?? throw new RowError("Co-district with LGD code {$r['co_district_lgd_code']} not found.");
                $this->assertDistrict($c->district_id, 'user');

                return ['level' => 'co_district', 'district_id' => $c->district_id, 'co_district_id' => $c->id];
            case Role::AGENCY_USER:
                $agencyCode = $this->req($r, 'agency_code');

                return [
                    'level' => 'agency',
                    'district_id' => $district()->id,
                    'implementing_agency_id' => ImplementingAgency::where('code', $agencyCode)->value('id')
                        ?? throw new RowError("Unknown agency code {$agencyCode}. Import a work for that agency first, or add it in admin."),
                ];
            default:
                return null; // state roles see the whole state; field users see their assigned works
        }
    }

    /** A district importer may only change users whose role and access already sit inside their district. */
    private function assertCanManageUser(User $user, array $allowedRoles): void
    {
        if (array_diff($user->getRoleNames()->all(), $allowedRoles)) {
            throw new RowError("User {$user->mobile} has a role you cannot change.");
        }
        if ($this->allowedDistricts === null) {
            return;
        }
        $outside = $user->jurisdictions()->where(fn ($q) => $q->whereNull('district_id')->orWhereNotIn('district_id', $this->allowedDistricts))->exists()
            || Work::withoutGlobalScope(JurisdictionScope::class)->whereNotIn('district_id', $this->allowedDistricts)
                ->where(fn ($q) => $q->where('assigned_field_user_id', $user->id)
                    ->orWhereIn('id', WorkAssignment::query()->select('work_id')->where('user_id', $user->id)))
                ->exists();
        if ($outside) {
            throw new RowError("User {$user->mobile} also has access outside your district. Ask the state administrator to change them.");
        }
    }

    private function jurisdictionRows(User $user): array
    {
        return $user->jurisdictions()->orderBy('id')->get()
            ->map(fn ($j) => array_filter($j->only(['level', 'district_id', 'co_district_id', 'implementing_agency_id']), fn ($v) => $v !== null))
            ->all();
    }

    /** Accepts 98765 43210, +91-9876543210 or 09876543210 and returns the 10 digits. */
    private function mobile(string $v): string
    {
        $digits = preg_replace('/\D/', '', $v);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (! preg_match('/^[6-9]\d{9}$/', $digits)) {
            throw new RowError("mobile '{$v}' is not a 10-digit Indian mobile number.");
        }

        return $digits;
    }

    private function work(array $r, string $sheet, int $rowNo): string
    {
        $code = $this->req($r, 'code');
        $erId = $r['erupantor_id'] ?? null;

        $district = District::where('lgd_code', $this->req($r, 'district_lgd_code'))->first()
            ?? throw new RowError("District with LGD code {$r['district_lgd_code']} not found.");
        $this->assertDistrict($district->id);

        $coDistrict = null;
        if (! empty($r['co_district_lgd_code'])) {
            $coDistrict = CoDistrict::where('lgd_code', $this->req($r, 'co_district_lgd_code'))->first()
                ?? throw new RowError("Co-district with LGD code {$r['co_district_lgd_code']} not found.");
            if ($coDistrict->district_id !== $district->id) {
                throw new RowError("Co-district {$r['co_district_lgd_code']} is not in district {$r['district_lgd_code']}.");
            }
        }

        $constituencyId = null;
        if (! empty($r['constituency_ac_no'])) {
            $constituencyId = Constituency::where('ac_no', (int) $r['constituency_ac_no'])->value('id')
                ?? throw new RowError("Assembly constituency {$r['constituency_ac_no']} not found.");
        }

        $template = StageTemplate::where('code', $this->req($r, 'stage_template_code'))->first()
            ?? throw new RowError("Stage template {$r['stage_template_code']} not found. Set it up under stage templates first.");

        $sanctioned = $this->rupeesToPaise($this->req($r, 'sanctioned_amount_rs'), 'sanctioned_amount_rs');
        if ($sanctioned <= 0) {
            throw new RowError('Sanctioned amount must be more than zero.');
        }

        // Match on e-Rupantor ID first, then code.
        $work = ($erId ? Work::withoutGlobalScope(JurisdictionScope::class)->where('erupantor_id', $erId)->first() : null)
            ?? Work::withoutGlobalScope(JurisdictionScope::class)->where('code', $code)->first();
        if ($work && $erId && $work->erupantor_id && $work->erupantor_id !== $erId) {
            throw new RowError("Code {$code} already belongs to e-Rupantor ID {$work->erupantor_id}.");
        }
        if ($work && $work->code !== $code && Work::withoutGlobalScope(JurisdictionScope::class)->where('code', $code)->exists()) {
            throw new RowError("Code {$code} is already used by another work.");
        }
        if ($work) {
            $this->assertDistrict($work->district_id);
        }

        $isNew = ! $work;
        $work ??= new Work;
        $work->fill([
            'code' => $code,
            'erupantor_id' => $erId ?? $work->erupantor_id,
            'name_en' => $this->req($r, 'name_en'),
            'name_as' => $r['name_as'] ?? $work->name_as,
            'district_id' => $district->id,
            'co_district_id' => $coDistrict?->id,
            'constituency_id' => $constituencyId,
            'scheme_type_id' => $this->master(SchemeType::class, $this->req($r, 'scheme_type_code'), $r['scheme_type_name'] ?? null, 'scheme type'),
            'fund_source_id' => $this->master(FundSource::class, $this->req($r, 'fund_source_code'), $r['fund_source_name'] ?? null, 'fund source'),
            'implementing_agency_id' => $this->master(ImplementingAgency::class, $this->req($r, 'agency_code'), $r['agency_name'] ?? null, 'agency'),
            'financial_year_id' => $this->financialYear($this->req($r, 'financial_year')),
            'stage_template_id' => $template->id,
            'sanctioned_amount' => $sanctioned,
            'sanction_order_no' => $r['sanction_order_no'] ?? $work->sanction_order_no,
            'sanction_date' => $this->date($r['sanction_date'] ?? null, 'sanction_date') ?? $work->sanction_date,
            'target_completion_date' => $this->date($r['target_completion_date'] ?? null, 'target_completion_date') ?? $work->target_completion_date,
            'site_radius_m' => isset($r['site_radius_m']) ? max(20, (int) $r['site_radius_m']) : ($work->site_radius_m ?? 200),
        ]);
        if ($isNew) {
            $work->current_stage_item_id = $template->items()->value('id');
            $work->is_pilot = true;
        } elseif ($work->isDirty('stage_template_id') && $work->progressUpdates()->withoutGlobalScopes()->exists()) {
            throw new RowError('Stage template cannot change after progress has been recorded.');
        }

        if (! empty($r['field_user_mobile'])) {
            $field = User::where('mobile', (string) $r['field_user_mobile'])->first();
            if ($field && $field->hasRole(Role::FIELD_USER)) {
                $work->assigned_field_user_id = $field->id;
            } else {
                $this->report->warning($sheet, $rowNo, "No field officer with mobile {$r['field_user_mobile']}; work saved without one.");
            }
        }

        $outcome = $this->save($work);

        if (isset($r['site_lat'], $r['site_lng'])) {
            [$lat, $lng] = [(float) $r['site_lat'], (float) $r['site_lng']];
            // Assam lies roughly within 24–28.5°N, 89.5–96.5°E; catches swapped or garbled coordinates.
            if ($lat < 24 || $lat > 28.5 || $lng < 89.5 || $lng > 96.5) {
                throw new RowError("Site {$lat}, {$lng} is outside Assam. Check that latitude and longitude are not swapped.");
            }
            $work->setSite($lat, $lng);
        }
        if ($work->assigned_field_user_id) {
            WorkAssignment::firstOrCreate(['work_id' => $work->id, 'user_id' => $work->assigned_field_user_id, 'role' => 'field']);
        }

        return $outcome;
    }

    private function release(array $r): string
    {
        $ref = $this->req($r, 'work_code');
        $work = Work::withoutGlobalScope(JurisdictionScope::class)->where(fn ($q) => $q->where('code', $ref)->orWhere('erupantor_id', $ref))->first()
            ?? throw new RowError("Work {$ref} not found. Import it in the Works sheet first.");
        $this->assertDistrict($work->district_id);

        $amount = $this->rupeesToPaise($this->req($r, 'amount_rs'), 'amount_rs');
        if ($amount <= 0) {
            throw new RowError('Release amount must be more than zero.');
        }
        $date = $this->date($this->req($r, 'released_on'), 'released_on');

        $exists = FundRelease::withoutGlobalScope(JurisdictionScope::class)->where('work_id', $work->id)
            ->where('amount', $amount)->whereDate('released_on', $date)
            ->where('order_no', $r['order_no'] ?? null)->exists();
        if ($exists) {
            return 'skipped'; // already imported
        }

        $total = FundRelease::withoutGlobalScope(JurisdictionScope::class)->where('work_id', $work->id)->sum('amount') + $amount;
        if ($total > $work->sanctioned_amount) {
            throw new RowError('Total releases would exceed the sanctioned amount.');
        }

        FundRelease::create([
            'work_id' => $work->id,
            'amount' => $amount,
            'released_on' => $date,
            'order_no' => $r['order_no'] ?? null,
            'source' => 'import',
            'entered_by' => $this->user->id,
        ]);

        return 'created';
    }

    // ---- helpers -----------------------------------------------------------

    private function save($model): string
    {
        $isNew = ! $model->exists;
        $dirty = $model->isDirty();
        $model->save();

        return $isNew ? 'created' : ($dirty ? 'updated' : 'skipped');
    }

    /** Column length limits from the schema, checked here so the user gets a clear message. */
    private const MAX_LENGTH = [
        'lgd_code' => 10, 'district_lgd_code' => 10, 'co_district_lgd_code' => 10, 'erupantor_code' => 20,
        'mobile' => 20, 'name' => 255, 'designation' => 255, 'role' => 30,
        'code' => 40, 'erupantor_id' => 40, 'work_code' => 40, 'scheme_type_code' => 40, 'fund_source_code' => 40, 'agency_code' => 40,
    ];

    private function req(array $r, string $key): string
    {
        $v = $r[$key] ?? null;
        if ($v === null || $v === '') {
            throw new RowError("{$key} is required.");
        }
        $v = is_float($v) && floor($v) === $v ? (string) (int) $v : (string) $v;
        if (isset(self::MAX_LENGTH[$key]) && mb_strlen($v) > self::MAX_LENGTH[$key]) {
            throw new RowError("{$key} '{$v}' is longer than ".self::MAX_LENGTH[$key].' characters.');
        }

        return $v;
    }

    private function assertDistrict(int $districtId, string $what = 'work'): void
    {
        if ($this->allowedDistricts !== null && ! in_array($districtId, $this->allowedDistricts, true)) {
            throw new RowError("This {$what} is outside your district.");
        }
    }

    /** @param class-string $class */
    private function master(string $class, string $code, ?string $name, string $label): int
    {
        $id = $class::where('code', $code)->value('id');
        if ($id) {
            return $id;
        }
        if (! $name) {
            throw new RowError("Unknown {$label} code {$code}. Add a {$label} name column value to create it.");
        }

        return $class::create(['code' => $code, 'name_en' => $name])->id;
    }

    private function financialYear(string $code): int
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $code, $m) || (int) $m[2] !== ((int) $m[1] + 1) % 100) {
            throw new RowError("Financial year {$code} should look like 2026-27.");
        }

        return FinancialYear::firstOrCreate(['code' => $code], [
            'starts_on' => "{$m[1]}-04-01",
            'ends_on' => ((int) $m[1] + 1).'-03-31',
        ])->id;
    }

    private function rupeesToPaise(string $v, string $field): int
    {
        $clean = str_replace([',', '₹', ' '], '', $v);
        if (! is_numeric($clean)) {
            throw new RowError("{$field} must be a number in rupees.");
        }

        return (int) round(((float) $clean) * 100);
    }

    private function date(?string $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y'] as $fmt) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$fmt, $v);
            } catch (InvalidFormatException) {
                continue;
            }
            if ($d && $d->format($fmt) === $v) {
                return $d->toDateString();
            }
        }
        throw new RowError("{$field} '{$v}' is not a date (use YYYY-MM-DD or DD-MM-YYYY).");
    }

    private function bool(mixed $v): bool
    {
        return in_array(strtolower((string) $v), ['1', 'yes', 'y', 'true', 'হয়', 'হ্যাঁ'], true);
    }

    private function recordBatch(?string $fileName): void
    {
        SyncBatch::create([
            'direction' => 'in',
            'mode' => 'file',
            'entity' => 'workbook',
            'started_at' => now(),
            'finished_at' => now(),
            'counts_json' => $this->report->toArray() + ['file' => $fileName],
            'file_path' => null,
            'status' => $this->report->failedRows() ? 'partial' : 'done',
            'started_by' => $this->user->id,
        ]);
    }
}
