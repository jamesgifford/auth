<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Support\Fixtures\UserModels;

/**
 * Two classes in a single file. The installer refuses to modify because the
 * modifier requires exactly one class definition per file to avoid ambiguity.
 */

class UserWithUnusualStructure
{
    public string $name = '';
}

class UserWithUnusualStructureSidekick
{
    public string $role = '';
}
