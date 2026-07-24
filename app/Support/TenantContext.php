<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Organization;

final class TenantContext
{
    public function __construct(
        public readonly Organization $organization,
        public readonly Branch $branch,
        public readonly string $role,
    ) {}

    public function can(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
