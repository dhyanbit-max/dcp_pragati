<?php

namespace App\Domain\Access;

/** Role names. See docs/04-roles-and-access.md §1. */
final class Role
{
    public const SYSTEM_ADMIN = 'system_admin';

    public const STATE_ADMIN = 'state_admin';

    public const STATE_VIEWER = 'state_viewer';

    public const DISTRICT_COLLECTOR = 'district_collector';

    public const DPC = 'dpc';

    public const CO_DISTRICT_OFFICER = 'co_district_officer';

    public const AGENCY_USER = 'agency_user';

    public const FIELD_USER = 'field_user';

    public const STATE_ROLES = [self::SYSTEM_ADMIN, self::STATE_ADMIN, self::STATE_VIEWER];

    public const ALL = [
        self::SYSTEM_ADMIN, self::STATE_ADMIN, self::STATE_VIEWER, self::DISTRICT_COLLECTOR,
        self::DPC, self::CO_DISTRICT_OFFICER, self::AGENCY_USER, self::FIELD_USER,
    ];
}
