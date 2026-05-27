<?php

declare(strict_types=1);

namespace Progravity\Auth\Tests\Feature\Accounts;

use Progravity\Auth\Exceptions\InvalidRolesConfigException;
use Progravity\Auth\Roles\RolesConfig;
use Progravity\Auth\Tests\TestCase;

class RolesConfigTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function validRoles(): array
    {
        return [
            'owner' => ['name' => 'Owner', 'description' => 'Owns it.', 'system' => true, 'sort_order' => 1],
            'admin' => ['name' => 'Administrator', 'system' => true, 'sort_order' => 2],
            'auditor' => ['name' => 'Auditor', 'system' => false, 'sort_order' => 10],
        ];
    }

    public function test_default_config_constructs_successfully(): void
    {
        $config = new RolesConfig(config('progravity.auth.roles'));

        $this->assertTrue($config->hasRole('owner'));
        $this->assertCount(4, $config->roles());
    }

    public function test_missing_owner_key_throws_naming_owner(): void
    {
        $this->expectException(InvalidRolesConfigException::class);
        $this->expectExceptionMessageMatches('/owner/');

        new RolesConfig([
            'admin' => ['name' => 'Administrator', 'system' => true],
        ]);
    }

    public function test_owner_declared_non_system_throws(): void
    {
        $this->expectException(InvalidRolesConfigException::class);
        $this->expectExceptionMessageMatches('/owner/');

        new RolesConfig([
            'owner' => ['name' => 'Owner', 'system' => false],
        ]);
    }

    public function test_empty_roles_array_throws(): void
    {
        $this->expectException(InvalidRolesConfigException::class);

        new RolesConfig([]);
    }

    public function test_invalid_key_format_throws(): void
    {
        $this->expectException(InvalidRolesConfigException::class);

        new RolesConfig([
            'owner' => ['name' => 'Owner', 'system' => true],
            'Bad-Key' => ['name' => 'Bad', 'system' => false],
        ]);
    }

    public function test_custom_role_using_reserved_system_key_without_system_flag_throws(): void
    {
        $this->expectException(InvalidRolesConfigException::class);
        $this->expectExceptionMessageMatches('/admin/');

        new RolesConfig([
            'owner' => ['name' => 'Owner', 'system' => true],
            'admin' => ['name' => 'Administrator', 'system' => false],
        ]);
    }

    public function test_accessors_return_expected_values(): void
    {
        $config = new RolesConfig($this->validRoles());

        $this->assertSame($this->validRoles(), $config->roles());

        $this->assertTrue($config->hasRole('owner'));
        $this->assertFalse($config->hasRole('nope'));

        $this->assertSame('Owner', $config->get('owner')['name']);

        $this->assertSame(['owner', 'admin'], array_keys($config->systemRoles()));
        $this->assertSame(['auditor'], array_keys($config->customRoles()));
    }

    public function test_get_throws_for_unknown_key(): void
    {
        $config = new RolesConfig($this->validRoles());

        $this->expectException(\InvalidArgumentException::class);

        $config->get('missing');
    }
}
