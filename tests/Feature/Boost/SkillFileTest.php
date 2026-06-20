<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Boost;

use JamesGifford\Auth\Tests\TestCase;

/**
 * Guards the Laravel Boost skill: it must exist at the convention path, begin
 * with `---` on line 1 (or Boost's start-anchored parser silently skips it),
 * carry the required frontmatter, and have `name` equal to its folder name.
 * The identifier checks catch the skill drifting out of sync with the code.
 */
class SkillFileTest extends TestCase
{
    private const SKILL_DIR = 'jamesgifford-auth';

    public function test_skill_file_exists_at_the_convention_path(): void
    {
        $this->assertFileExists($this->skillPath());
    }

    public function test_first_line_is_exactly_the_frontmatter_delimiter(): void
    {
        $contents = (string) file_get_contents($this->skillPath());

        // No BOM, comment, blank line, or whitespace may precede `---`.
        $this->assertStringStartsWith("---\n", $contents);

        $firstLine = strtok($contents, "\n");
        $this->assertSame('---', $firstLine);
    }

    public function test_frontmatter_has_required_name_and_description(): void
    {
        $frontmatter = $this->parseFrontmatter();

        $this->assertArrayHasKey('name', $frontmatter);
        $this->assertArrayHasKey('description', $frontmatter);
        $this->assertNotSame('', trim($frontmatter['description']));
    }

    public function test_frontmatter_name_matches_the_parent_folder_name(): void
    {
        $frontmatter = $this->parseFrontmatter();

        $this->assertSame(self::SKILL_DIR, $frontmatter['name']);
    }

    public function test_body_references_real_code_identifiers(): void
    {
        $contents = (string) file_get_contents($this->skillPath());

        foreach ([
            'jamesgifford-auth.account.switch',
            'jamesgifford-auth.account.list',
            'switchToAccount',
            'transferOwnership',
            'isAdminOf',
            'auth.current-account',
            'JamesGifford\\Auth\\PublicId\\Concerns\\HasPublicId',
        ] as $needle) {
            $this->assertStringContainsString($needle, $contents, "Skill should reference {$needle}");
        }
    }

    private function skillPath(): string
    {
        return __DIR__.'/../../../resources/boost/skills/'.self::SKILL_DIR.'/SKILL.md';
    }

    /**
     * @return array<string, string>
     */
    private function parseFrontmatter(): array
    {
        $contents = (string) file_get_contents($this->skillPath());

        $this->assertSame(1, preg_match('/\A---\n(.*?)\n---\n/s', $contents, $m), 'Frontmatter block not found.');

        $pairs = [];
        foreach (preg_split('/\n/', $m[1]) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $kv) === 1) {
                $pairs[$kv[1]] = trim($kv[2]);
            }
        }

        return $pairs;
    }
}
