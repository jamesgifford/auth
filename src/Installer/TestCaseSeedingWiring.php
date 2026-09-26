<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use JamesGifford\Auth\Exceptions\InvalidRoleException;
use PhpParser\BuilderFactory;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;
use Throwable;

/**
 * Wires `protected $seed = true;` into a consuming application's base test
 * case so RefreshDatabase seeds the database — and therefore account_roles —
 * for every test. Without it, RefreshDatabase rebuilds the schema fresh per
 * test but never runs DatabaseSeeder, so any test that creates an account
 * (e.g. via registration) hits
 * {@see InvalidRoleException}, even though
 * config('jamesgifford.auth.roles') is perfectly valid.
 *
 * Mirrors {@see DatabaseSeederWiring}'s analyze()/wire()/commit() shape, but
 * scoped down: there is nothing to remove on uninstall — a bare boolean
 * property carries no reference to any package class, so leaving it behind
 * is harmless — and detection needs no import-map resolution beyond the
 * `#[Seed]` attribute case.
 *
 * Never touched when the class already seeds some other way: a `$seed`
 * property (any value — an explicit `false` is still a deliberate choice), a
 * `#[Seed]` attribute, or an overridden `seeder()` method (which takes
 * priority over `$seed` entirely). A consumer's own choice is always
 * respected.
 */
final class TestCaseSeedingWiring
{
    private const SEED_ATTRIBUTE = 'Illuminate\\Foundation\\Testing\\Attributes\\Seed';

    public function __construct(
        private readonly Parser $parser,
        private readonly Standard $printer,
        private readonly TransientFileWriter $writer,
    ) {}

    public function path(): string
    {
        return base_path('tests'.DIRECTORY_SEPARATOR.'TestCase.php');
    }

    public function analyze(): TestCaseSeedingAnalysis
    {
        $path = $this->path();

        if (! is_file($path)) {
            return $this->unusable(fileExists: false, reason: 'file does not exist');
        }

        $code = (string) file_get_contents($path);

        try {
            $ast = $this->parser->parse($code);
        } catch (Throwable) {
            $ast = null;
        }

        if ($ast === null) {
            return $this->unusable(parseable: false, reason: 'file is not parseable PHP');
        }

        [$namespace, $importMap, $scan] = NameResolver::context($ast);

        $classNodes = array_values(array_filter(
            $scan,
            static fn (Node $stmt): bool => $stmt instanceof Stmt\Class_,
        ));

        if (count($classNodes) === 0) {
            return $this->unusable(reason: 'no class declaration found in the file');
        }

        if (count($classNodes) > 1) {
            return $this->unusable(reason: 'multiple class declarations found in a single file');
        }

        $classNode = $classNodes[0];

        return new TestCaseSeedingAnalysis(
            fileExists: true,
            parseable: true,
            className: $classNode->name?->toString(),
            alreadySeeds: $this->alreadySeeds($classNode, $namespace, $importMap),
            unusualReason: null,
        );
    }

    /**
     * Plan the insertion of `protected $seed = true;`. Writes nothing.
     */
    public function wire(TestCaseSeedingAnalysis $analysis): TestCaseSeedingChange
    {
        if (! $analysis->isModifiable()) {
            throw new RuntimeException(
                'Cannot wire test seeding: '.($analysis->unusualReason ?? 'unknown reason')
            );
        }

        $originalCode = (string) file_get_contents($this->path());

        if ($analysis->alreadySeeds) {
            return new TestCaseSeedingChange(
                originalCode: $originalCode,
                modifiedCode: $originalCode,
                changed: false,
            );
        }

        $oldStmts = $this->parser->parse($originalCode) ?? [];
        $oldTokens = $this->parser->getTokens();

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new CloningVisitor);
        $newStmts = $traverser->traverse($oldStmts);

        [, , $scan] = NameResolver::context($newStmts);

        foreach ($scan as $stmt) {
            if ($stmt instanceof Stmt\Class_) {
                $stmt->stmts = $this->insertSeedProperty($stmt->stmts);
            }
        }

        return new TestCaseSeedingChange(
            originalCode: $originalCode,
            modifiedCode: BlankLine::render($this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens)),
            changed: true,
        );
    }

    /**
     * Apply a planned change through the transient writer, so a failed write
     * leaves the consumer's file exactly as it was. A no-op change writes
     * nothing at all.
     */
    public function commit(TestCaseSeedingChange $change): void
    {
        if (! $change->changed) {
            return;
        }

        $this->writer->apply($this->path(), $change->modifiedCode);
    }

    /**
     * Insert right after the last leading trait-use statement (matching
     * where a human would naturally place it — after `use CreatesApplication`
     * and similar), or at the top of the class when there is none — set off
     * by a blank line from whatever surrounds it, as the consuming app's Pint
     * (class_attributes_separation) requires.
     *
     * @param  array<int, Stmt>  $body
     * @return array<int, Stmt>
     */
    private function insertSeedProperty(array $body): array
    {
        $position = 0;
        foreach ($body as $index => $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                $position = $index + 1;
            }
        }

        $insert = [$this->seedProperty()];
        if ($position > 0) {
            array_unshift($insert, BlankLine::node());
        }
        if ($position < count($body)) {
            $insert[] = BlankLine::node();
        }

        array_splice($body, $position, 0, $insert);

        return $body;
    }

    private function seedProperty(): Stmt\Property
    {
        $property = (new BuilderFactory)->property('seed')
            ->makeProtected()
            ->setDefault(true)
            ->getNode();

        $property->setAttribute('comments', [
            new Comment('// RefreshDatabase does not seed by default; jamesgifford/auth requires'),
            new Comment('// account_roles to be seeded. See its README, "Testing in your application".'),
        ]);

        return $property;
    }

    /**
     * @param  array<string, string>  $importMap
     */
    private function alreadySeeds(Stmt\Class_ $classNode, ?string $namespace, array $importMap): bool
    {
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->name->toString() === 'seed') {
                        return true;
                    }
                }
            }

            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toString() === 'seeder') {
                return true;
            }
        }

        foreach ($classNode->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (NameResolver::resolve($attr->name, $namespace, $importMap) === self::SEED_ATTRIBUTE) {
                    return true;
                }
            }
        }

        return false;
    }

    private function unusable(
        bool $fileExists = true,
        bool $parseable = true,
        ?string $reason = null,
    ): TestCaseSeedingAnalysis {
        return new TestCaseSeedingAnalysis(
            fileExists: $fileExists,
            parseable: $parseable,
            className: null,
            alreadySeeds: false,
            unusualReason: $reason,
        );
    }
}
