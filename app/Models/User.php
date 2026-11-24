<?php

namespace App\Models;

use App\Domain\Access\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    protected $fillable = [
        'name', 'mobile', 'email', 'password', 'designation', 'preferred_locale', 'whatsapp_opt_in', 'is_active', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'whatsapp_opt_in' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'mobile', 'email', 'designation', 'is_active'])->logOnlyDirty();
    }

    public function jurisdictions(): HasMany
    {
        return $this->hasMany(UserJurisdiction::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkAssignment::class);
    }

    public function hasStateScope(): bool
    {
        return $this->hasAnyRole(Role::STATE_ROLES);
    }

    /**
     * Restrict a works query to what this user may see. See docs/04-roles-and-access.md §2.
     * Applied by JurisdictionScope; call directly where no authenticated user is in context.
     */
    public function applyWorkScope(Builder $query, string $table = 'works'): Builder
    {
        if ($this->hasStateScope()) {
            return $query;
        }

        if ($this->hasRole(Role::FIELD_USER)) {
            return $query->where(function (Builder $q) use ($table) {
                $q->where("$table.assigned_field_user_id", $this->id)
                    ->orWhereIn("$table.id", WorkAssignment::query()->select('work_id')->where('user_id', $this->id));
            });
        }

        $jurisdictions = $this->loadMissing('jurisdictions')->jurisdictions;
        if ($jurisdictions->isEmpty()) {
            return $query->whereRaw('false');
        }

        return $query->where(function (Builder $q) use ($jurisdictions, $table) {
            foreach ($jurisdictions as $j) {
                $q->orWhere(function (Builder $q) use ($j, $table) {
                    match ($j->level) {
                        'state' => $q->whereRaw('true'),
                        'district' => $q->where("$table.district_id", $j->district_id),
                        'co_district' => $q->where("$table.co_district_id", $j->co_district_id),
                        'agency' => $q->where("$table.implementing_agency_id", $j->implementing_agency_id)
                            ->when($j->district_id, fn ($q) => $q->where("$table.district_id", $j->district_id)),
                        default => $q->whereRaw('false'),
                    };
                });
            }
        });
    }
}
