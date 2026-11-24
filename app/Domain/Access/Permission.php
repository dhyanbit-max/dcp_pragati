<?php

namespace App\Domain\Access;

/** Permission names and the role → permission matrix from docs/04-roles-and-access.md §3. */
final class Permission
{
    public const VIEW_DASHBOARD = 'view dashboard';

    public const VIEW_MAP = 'view map';

    public const SUBMIT_PROGRESS = 'submit progress';

    public const APPROVE_PROGRESS = 'approve progress';

    public const BULK_EDIT = 'bulk edit';

    public const RECORD_RELEASE = 'record release';

    public const HANDLE_ALERTS = 'handle alerts';

    public const RUN_REPORTS = 'run reports';

    public const AQ_DESK = 'aq desk';

    public const MANAGE_MASTERS = 'manage masters';

    public const MANAGE_RULES = 'manage rules';

    public const MANAGE_USERS = 'manage users';

    public const ERUPANTOR_SYNC = 'erupantor sync';

    public const VIEW_AUDIT = 'view audit';

    public const HANDLE_GRIEVANCES = 'handle grievances';

    /** @return array<string, list<string>> */
    public static function matrix(): array
    {
        $all = (new \ReflectionClass(self::class))->getConstants();

        return [
            Role::SYSTEM_ADMIN => array_values(array_diff($all, [self::SUBMIT_PROGRESS, self::APPROVE_PROGRESS, self::BULK_EDIT, self::RECORD_RELEASE])),
            Role::STATE_ADMIN => array_values(array_diff($all, [self::SUBMIT_PROGRESS])),
            Role::STATE_VIEWER => [self::VIEW_DASHBOARD, self::VIEW_MAP, self::RUN_REPORTS, self::AQ_DESK],
            Role::DISTRICT_COLLECTOR => [self::VIEW_DASHBOARD, self::VIEW_MAP, self::HANDLE_ALERTS, self::RUN_REPORTS, self::HANDLE_GRIEVANCES],
            Role::DPC => [
                self::VIEW_DASHBOARD, self::VIEW_MAP, self::SUBMIT_PROGRESS, self::APPROVE_PROGRESS, self::BULK_EDIT,
                self::RECORD_RELEASE, self::HANDLE_ALERTS, self::RUN_REPORTS, self::AQ_DESK, self::MANAGE_MASTERS,
                self::MANAGE_USERS, self::ERUPANTOR_SYNC, self::VIEW_AUDIT, self::HANDLE_GRIEVANCES,
            ],
            Role::CO_DISTRICT_OFFICER => [self::VIEW_DASHBOARD, self::VIEW_MAP, self::SUBMIT_PROGRESS, self::HANDLE_ALERTS, self::RUN_REPORTS, self::HANDLE_GRIEVANCES],
            Role::AGENCY_USER => [self::VIEW_DASHBOARD, self::VIEW_MAP, self::SUBMIT_PROGRESS, self::HANDLE_ALERTS, self::RUN_REPORTS],
            Role::FIELD_USER => [self::VIEW_MAP, self::SUBMIT_PROGRESS, self::HANDLE_ALERTS],
        ];
    }
}
