<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use Closure;
use JamesGifford\Auth\Database\DevDataSeeder;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\Database\Seeders\ApplyIdOffsetsSeeder;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;
use Throwable;

/**
 * Wires the package's seeders into a consuming application's DatabaseSeeder so
 * `migrate:fresh --seed` / `migrate:refresh --seed` rebuild the package's data
 * with no manual file edits — and removes that wiring on uninstall.
 *
 * Mirrors {@see UserModelModifier}: analyze() inspects, wire()/unwire() plan
 * without touching disk, and {@see TransientFileWriter} commits.
 *
 * Detection is AST-based and position-independent: a call counts as wired
 * wherever it appears, in any of the forms a developer may have written it
 * (imported short name, fully-qualified, inside an array argument, nested in a
 * control structure). That is what lets the package's edits coexist with
 * unrelated changes to the same file.
 *
 * analyze() also reports, advisory-only, whether the class uses Laravel's
 * WithoutModelEvents trait. Nothing here acts on it — see
 * {@see DatabaseSeederAnalysis} for what it means.
 */
final class DatabaseSeederWiring
{
    /** Required account roles — every environment. */
    public const ROLES = AccountRoleSeeder::class;

    /** Development fixtures — the seeder self-guards by environment. */
    public const DEV_DATA = DevDataSeeder::class;

    /** Auto-increment offsets, re-applied after the fixtures above. */
    public const ID_OFFSETS = ApplyIdOffsetsSeeder::class;

    /**
     * Canonical order: roles must precede the fixtures that reference them,
     * and offsets must follow the fixtures they reserve room above.
     *
     * @var list<string>
     */
    public const CANONICAL_ORDER = [self::ROLES, self::DEV_DATA, self::ID_OFFSETS];

    /**
     * AST attribute marking an `if` whose body lost a statement to the
     * package's own removal pass. Cleanup only drops guards carrying it, so a
     * consumer's own empty `if` is never deleted. Public because the visitors
     * are separate (anonymous) classes.
     */
    public const EMPTIED_GUARD = 'jamesgifford_auth_emptied_guard';

    /**
     * Laravel's opt-in trait that runs a seeder inside Model::withoutEvents().
     * Detected — never touched — so commands can warn about the guards and
     * observers it silences.
     */
    private const WITHOUT_MODEL_EVENTS = 'Illuminate\\Database\\Console\\Seeds\\WithoutModelEvents';

    /**
     * Human-readable note written above each inserted call. Cosmetic only:
     * detection is AST-based, so a developer who deletes these keeps working
     * wiring.
     *
     * @var array<string, list<string>>
     */
    private const COMMENTS = [
        self::ROLES => ['// Auth: required account roles — ALL environments.'],
        self::DEV_DATA => [
            '// Auth: development fixtures — the seeder refuses outside',
            '// local/staging and always in production.',
        ],
        self::ID_OFFSETS => [
            '// Auth: reserve low IDs for the fixtures above. No-op when no',
            '// offsets are configured, and on SQLite.',
        ],
    ];

    public function __construct(
        private readonly Parser $parser,
        private readonly Standard $printer,
        private readonly TransientFileWriter $writer,
    ) {}

    /**
     * The paste-in line for wiring a seeder by hand. Single-sourced so the
     * stub and every command's instructions print the identical snippet.
     */
    public static function callLine(string $fqcn): string
    {
        return '$this->call(\\'.$fqcn.'::class);';
    }

    /**
     * The advisory for a DatabaseSeeder carrying WithoutModelEvents, as bare
     * text lines (the first is the sentence, the rest continue it).
     * Single-sourced so install, setup, and verification say exactly the same
     * thing; each caller adds its own marker and indentation.
     *
     * Deliberately does NOT claim public_id is at risk — generation runs off
     * Eloquent's unique-id hook, not a model event.
     *
     * @return list<string>
     */
    public static function withoutModelEventsWarning(): array
    {
        return [
            'database/seeders/DatabaseSeeder.php uses WithoutModelEvents, which disables all',
            'model events for the whole seeding run — including the guard against deleting a',
            'system account role, and any observers your application registers.',
            'public_id generation is unaffected.',
        ];
    }

    public function path(): string
    {
        return database_path('seeders'.DIRECTORY_SEPARATOR.'DatabaseSeeder.php');
    }

    public function analyze(): DatabaseSeederAnalysis
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

        [$namespace, $importMap, $scan] = $this->resolveContext($ast);

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

        $extendsSeeder = $classNode->extends !== null
            && $this->resolveName($classNode->extends, $namespace, $importMap) === 'Illuminate\\Database\\Seeder';

        $runMethod = $this->findRunMethod($classNode);

        // Walk the class ONCE and intersect, rather than re-walking per seeder.
        $called = $this->calledClassNames($classNode, $namespace, $importMap);
        $wired = array_values(array_filter(
            self::CANONICAL_ORDER,
            static fn (string $fqcn): bool => in_array($fqcn, $called, true),
        ));

        return new DatabaseSeederAnalysis(
            fileExists: true,
            parseable: true,
            className: $classNode->name?->toString(),
            namespace: $namespace,
            extendsSeeder: $extendsSeeder,
            hasRunMethod: $runMethod !== null,
            wiredSeeders: $wired,
            usesWithoutModelEvents: $this->usesWithoutModelEvents($classNode, $namespace, $importMap),
            unusualReason: match (true) {
                ! $extendsSeeder => 'the class does not extend Illuminate\\Database\\Seeder',
                $runMethod === null => 'the class has no run() method',
                default => null,
            },
        );
    }

    /**
     * Plan the insertion of any of $seeders not already wired. Writes nothing.
     *
     * @param  list<string>  $seeders  desired FQCNs, canonical order
     */
    public function wire(DatabaseSeederAnalysis $analysis, array $seeders): DatabaseSeederChange
    {
        if (! $analysis->isModifiable()) {
            throw new RuntimeException(
                'Cannot wire DatabaseSeeder: '.($analysis->unusualReason ?? 'unknown reason')
            );
        }

        $originalCode = (string) file_get_contents($this->path());
        $toAdd = $analysis->missing($seeders);

        if ($toAdd === []) {
            return new DatabaseSeederChange(
                originalCode: $originalCode,
                modifiedCode: $originalCode,
                addedSeeders: [],
                removedSeeders: [],
            );
        }

        $oldStmts = $this->parser->parse($originalCode) ?? [];
        $oldTokens = $this->parser->getTokens();

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new CloningVisitor);
        $newStmts = $traverser->traverse($oldStmts);

        [$namespace, $importMap, $scan] = $this->resolveContext($newStmts);

        foreach ($scan as $stmt) {
            if (! $stmt instanceof Stmt\Class_) {
                continue;
            }

            $run = $this->findRunMethod($stmt);
            if ($run === null) {
                continue;
            }

            $body = $run->stmts ?? [];
            foreach ($toAdd as $fqcn) {
                $body = $this->insertCall($body, $fqcn, $namespace, $importMap);
            }
            $run->stmts = $body;
        }

        return new DatabaseSeederChange(
            originalCode: $originalCode,
            modifiedCode: $this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens),
            addedSeeders: $toAdd,
            removedSeeders: [],
        );
    }

    /**
     * A complete DatabaseSeeder for an app that has none. Follows
     * ModelPublisher's conventions for consumer-facing generated files.
     *
     * @param  list<string>  $seeders
     */
    public function stub(array $seeders): string
    {
        $lines = [];
        foreach ($seeders as $fqcn) {
            foreach (self::COMMENTS[$fqcn] ?? [] as $comment) {
                $lines[] = '        '.$comment;
            }
            $lines[] = '        '.self::callLine($fqcn);
            $lines[] = '';
        }
        $body = rtrim(implode("\n", $lines), "\n");

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Database\\Seeders;

        use Illuminate\\Database\\Seeder;

        class DatabaseSeeder extends Seeder
        {
            /**
             * Seed the application's database.
             */
            public function run(): void
            {
        {$body}
            }
        }

        PHP;
    }

    /**
     * Plan the SURGICAL removal of the package's calls: only our
     * `$this->call(...)` statements (and our entries inside an array call),
     * plus any control structure they leave genuinely dead and any package
     * import they leave unreferenced. Everything else is preserved.
     *
     * Writes nothing. This is the uninstall counterpart to {@see wire()}.
     */
    public function unwire(DatabaseSeederAnalysis $analysis): DatabaseSeederChange
    {
        $originalCode = (string) file_get_contents($this->path());

        if ($analysis->wiredSeeders === []) {
            return new DatabaseSeederChange(
                originalCode: $originalCode,
                modifiedCode: $originalCode,
                addedSeeders: [],
                removedSeeders: [],
            );
        }

        $oldStmts = $this->parser->parse($originalCode) ?? [];
        $oldTokens = $this->parser->getTokens();

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new CloningVisitor);
        $newStmts = $traverser->traverse($oldStmts);

        [$namespace, $importMap] = $this->resolveContext($newStmts);

        $removalTraverser = new NodeTraverser;
        $removalTraverser->addVisitor($this->removalVisitor($namespace, $importMap));
        $newStmts = $removalTraverser->traverse($newStmts);

        // Second pass: drop guards OUR removal emptied — judged only once the
        // calls are gone. A consumer's own empty `if` carries no marker and
        // survives.
        $guardTraverser = new NodeTraverser;
        $guardTraverser->addVisitor($this->emptiedGuardVisitor());
        $newStmts = $guardTraverser->traverse($newStmts);

        // Third pass: drop package imports nothing references any more.
        // References are collected AFTER the guard pass, so nothing inside a
        // dropped guard keeps an import alive.
        $importTraverser = new NodeTraverser;
        $importTraverser->addVisitor($this->importCleanupVisitor(
            $this->referencedNamesIn($newStmts, $namespace, $importMap),
        ));
        $newStmts = $importTraverser->traverse($newStmts);

        return new DatabaseSeederChange(
            originalCode: $originalCode,
            modifiedCode: $this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens),
            addedSeeders: [],
            // Only what actually left the file: a call in a position the
            // removal visitor does not handle (e.g. chained) is detected as
            // wired but survives, and must not be reported as removed.
            removedSeeders: array_values(array_diff($analysis->wiredSeeders, $this->wiredIn($newStmts))),
        );
    }

    /**
     * Apply a planned change through the transient writer, so a failed write
     * leaves the consumer's file exactly as it was. A no-op change writes
     * nothing at all.
     */
    public function commit(DatabaseSeederChange $change): void
    {
        if ($change->modifiedCode === $change->originalCode) {
            return;
        }

        $this->writer->apply($this->path(), $change->modifiedCode);
    }

    /**
     * Insert a `$this->call(\Fqcn::class);` statement at its canonical position
     * within a run() body: immediately after the last already-present canonical
     * predecessor, else immediately before the first canonical successor, else
     * at the top (package calls precede app seeders that may depend on them).
     *
     * @param  array<int, Stmt>  $body
     * @param  array<string, string>  $importMap
     * @return array<int, Stmt>
     */
    private function insertCall(array $body, string $fqcn, ?string $namespace, array $importMap): array
    {
        $rank = array_flip(self::CANONICAL_ORDER);
        $floor = 0;
        $ceiling = null;

        foreach ($body as $index => $stmt) {
            foreach ($this->calledClassNames($stmt, $namespace, $importMap) as $name) {
                if (! isset($rank[$name])) {
                    continue;
                }
                if ($rank[$name] < $rank[$fqcn]) {
                    $floor = max($floor, $index + 1);
                } elseif ($ceiling === null) {
                    $ceiling = $index;
                }
            }
        }

        // A successor only pulls the insertion earlier when it sits AFTER
        // every predecessor. When both share one statement (e.g. an array-form
        // call listing roles and offsets), the dependency wins: the new call
        // goes after the statement carrying its predecessor.
        $position = ($ceiling !== null && $ceiling >= $floor) ? $ceiling : $floor;

        array_splice($body, $position, 0, [$this->callStatement($fqcn)]);

        return $body;
    }

    /**
     * Removes our call statements, and our entries from array-form calls.
     * Name resolution is delegated back to this class so the removal side uses
     * exactly the same rules as detection.
     *
     * @param  array<string, string>  $importMap
     */
    private function removalVisitor(?string $namespace, array $importMap): NodeVisitorAbstract
    {
        $isOurs = function (Node\Expr $expr) use ($namespace, $importMap): bool {
            $name = $this->classNameOf($expr);

            return $name !== null
                && in_array($this->resolveName($name, $namespace, $importMap), self::CANONICAL_ORDER, true);
        };

        $isThisCall = fn (Node\Expr\MethodCall $call): bool => $this->isThisCall($call);

        return new class($isOurs, $isThisCall) extends NodeVisitorAbstract
        {
            /** @var list<Stmt\If_> */
            private array $enclosingIfs = [];

            public function __construct(
                private readonly Closure $isOurs,
                private readonly Closure $isThisCall,
            ) {}

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Stmt\If_) {
                    $this->enclosingIfs[] = $node;
                }

                return null;
            }

            public function leaveNode(Node $node): ?int
            {
                if ($node instanceof Stmt\If_) {
                    array_pop($this->enclosingIfs);

                    return null;
                }

                if (! $node instanceof Stmt\Expression) {
                    return null;
                }

                $call = $node->expr;
                if (! $call instanceof Node\Expr\MethodCall || ! ($this->isThisCall)($call)) {
                    return null;
                }

                $firstArg = $call->args[0] ?? null;
                if (! $firstArg instanceof Node\Arg) {
                    return null;
                }

                $value = $firstArg->value;

                if ($value instanceof Node\Expr\Array_) {
                    $kept = [];
                    foreach ($value->items as $item) {
                        if (($this->isOurs)($item->value)) {
                            continue;
                        }
                        $kept[] = $item;
                    }

                    if ($kept === []) {
                        return $this->removed();
                    }

                    $value->items = $kept;

                    return null;
                }

                return ($this->isOurs)($value) ? $this->removed() : null;
            }

            /**
             * Record WHERE the removal happened: the enclosing `if`, if any,
             * is now a candidate for the emptied-guard cleanup pass.
             */
            private function removed(): int
            {
                $enclosing = end($this->enclosingIfs);
                if ($enclosing instanceof Stmt\If_) {
                    $enclosing->setAttribute(DatabaseSeederWiring::EMPTIED_GUARD, true);
                }

                return NodeTraverser::REMOVE_NODE;
            }
        };
    }

    /**
     * Drops `if` statements OUR removal emptied (marked with EMPTIED_GUARD)
     * that now have no body and no elseif/else — genuinely dead code the
     * package created. Unmarked empty ifs are the consumer's and are kept.
     */
    private function emptiedGuardVisitor(): NodeVisitorAbstract
    {
        return new class extends NodeVisitorAbstract
        {
            /** @var list<Stmt\If_> */
            private array $enclosingIfs = [];

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Stmt\If_) {
                    $this->enclosingIfs[] = $node;
                }

                return null;
            }

            public function leaveNode(Node $node): ?int
            {
                if (! $node instanceof Stmt\If_) {
                    return null;
                }

                array_pop($this->enclosingIfs);

                if (! $node->getAttribute(DatabaseSeederWiring::EMPTIED_GUARD, false)
                    || $node->stmts !== []
                    || $node->elseifs !== []
                    || $node->else !== null) {
                    return null;
                }

                // Dropping this guard may in turn empty ITS enclosing guard.
                $enclosing = end($this->enclosingIfs);
                if ($enclosing instanceof Stmt\If_) {
                    $enclosing->setAttribute(DatabaseSeederWiring::EMPTIED_GUARD, true);
                }

                return NodeTraverser::REMOVE_NODE;
            }
        };
    }

    /**
     * Drops package seeder imports that nothing references any more. An
     * import whose class the file still mentions anywhere (e.g. passed to a
     * consumer helper) is load-bearing and kept — removing it would silently
     * repoint the surviving short name at the wrong namespace.
     *
     * @param  list<string>  $stillReferenced
     */
    private function importCleanupVisitor(array $stillReferenced): NodeVisitorAbstract
    {
        return new class($stillReferenced) extends NodeVisitorAbstract
        {
            /** @param list<string> $stillReferenced */
            public function __construct(private readonly array $stillReferenced) {}

            public function leaveNode(Node $node): ?int
            {
                if (! $node instanceof Stmt\Use_) {
                    return null;
                }

                $kept = [];
                foreach ($node->uses as $useItem) {
                    $fqcn = $useItem->name->toString();
                    if (in_array($fqcn, DatabaseSeederWiring::CANONICAL_ORDER, true)
                        && ! in_array($fqcn, $this->stillReferenced, true)) {
                        continue;
                    }
                    $kept[] = $useItem;
                }

                if ($kept === []) {
                    return NodeTraverser::REMOVE_NODE;
                }

                $node->uses = $kept;

                return null;
            }
        };
    }

    /**
     * Every FQCN the AST references OUTSIDE use statements — the survivors
     * that decide whether a package import is still load-bearing.
     *
     * @param  array<int, Node>  $stmts
     * @param  array<string, string>  $importMap
     * @return list<string>
     */
    private function referencedNamesIn(array $stmts, ?string $namespace, array $importMap): array
    {
        $found = [];
        $resolve = fn (Name $name): string => $this->resolveName($name, $namespace, $importMap);
        $collect = function (string $fqcn) use (&$found): void {
            $found[] = $fqcn;
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($resolve, $collect) extends NodeVisitorAbstract
        {
            private int $useDepth = 0;

            public function __construct(
                private readonly Closure $resolve,
                private readonly Closure $collect,
            ) {}

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
                    $this->useDepth++;
                } elseif ($node instanceof Name && $this->useDepth === 0) {
                    ($this->collect)(($this->resolve)($node));
                }

                return null;
            }

            public function leaveNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
                    $this->useDepth--;
                }

                return null;
            }
        });
        $traverser->traverse($stmts);

        return array_values(array_unique($found));
    }

    /**
     * The package seeders a statement list still calls, in canonical order —
     * analyze()'s detection re-run against an in-memory AST, so unwire() can
     * report what actually left the file.
     *
     * @param  array<int, Node>  $stmts
     * @return list<string>
     */
    private function wiredIn(array $stmts): array
    {
        [$namespace, $importMap, $scan] = $this->resolveContext($stmts);

        $called = [];
        foreach ($scan as $stmt) {
            if ($stmt instanceof Stmt\Class_) {
                $called = [...$called, ...$this->calledClassNames($stmt, $namespace, $importMap)];
            }
        }

        return array_values(array_filter(
            self::CANONICAL_ORDER,
            static fn (string $fqcn): bool => in_array($fqcn, $called, true),
        ));
    }

    private function callStatement(string $fqcn): Stmt\Expression
    {
        $statement = new Stmt\Expression(
            new Node\Expr\MethodCall(
                new Node\Expr\Variable('this'),
                new Node\Identifier('call'),
                [new Node\Arg(
                    new Node\Expr\ClassConstFetch(
                        new Name\FullyQualified($fqcn),
                        new Node\Identifier('class'),
                    ),
                )],
            ),
        );

        $comments = array_map(
            static fn (string $text): Comment => new Comment($text),
            self::COMMENTS[$fqcn] ?? [],
        );

        if ($comments !== []) {
            $statement->setAttribute('comments', $comments);
        }

        return $statement;
    }

    /**
     * Whether the class applies Illuminate's WithoutModelEvents trait, which
     * suppresses model events for the whole seeding run. Advisory only — it
     * changes nothing the package does — so every `use` statement in the body
     * is checked, and every name within each (`use A, B;` is one node), and
     * each is resolved through the shared rules so an aliased or group import
     * counts exactly as the plain one does.
     *
     * Single-file by construction: the trait applied on a PARENT seeder class,
     * or on a nested seeder this file calls, is invisible here.
     *
     * @param  array<string, string>  $importMap
     */
    private function usesWithoutModelEvents(Stmt\Class_ $classNode, ?string $namespace, array $importMap): bool
    {
        foreach ($classNode->stmts as $stmt) {
            if (! $stmt instanceof Stmt\TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $trait) {
                if ($this->resolveName($trait, $namespace, $importMap) === self::WITHOUT_MODEL_EVENTS) {
                    return true;
                }
            }
        }

        return false;
    }

    private function findRunMethod(Stmt\Class_ $classNode): ?Stmt\ClassMethod
    {
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toString() === 'run') {
                return $stmt;
            }
        }

        return null;
    }

    /**
     * Every class FQCN passed to a `$this->call(...)` inside the given node,
     * found by a recursive walk so nesting (an if, a match, a loop) is
     * irrelevant.
     *
     * @param  array<string, string>  $importMap
     * @return list<string>
     */
    private function calledClassNames(Node $root, ?string $namespace, array $importMap): array
    {
        $found = [];

        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = (new NodeFinder)->findInstanceOf($root, Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            if (! $this->isThisCall($call)) {
                continue;
            }

            foreach ($call->args as $arg) {
                if (! $arg instanceof Node\Arg) {
                    continue;
                }
                foreach ($this->classRefsIn($arg->value) as $name) {
                    $found[] = $this->resolveName($name, $namespace, $importMap);
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function isThisCall(Node\Expr\MethodCall $node): bool
    {
        return $node->var instanceof Node\Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'call';
    }

    /**
     * The `X::class` names inside a call argument — either the argument itself
     * or, for the array form, each element of it.
     *
     * @return list<Name>
     */
    private function classRefsIn(Node\Expr $expr): array
    {
        $name = $this->classNameOf($expr);
        if ($name !== null) {
            return [$name];
        }

        if ($expr instanceof Node\Expr\Array_) {
            $names = [];
            foreach ($expr->items as $item) {
                $itemName = $this->classNameOf($item->value);
                if ($itemName !== null) {
                    $names[] = $itemName;
                }
            }

            return $names;
        }

        return [];
    }

    /**
     * The class Name in an `X::class` fetch, or null when the expression is
     * anything else.
     */
    private function classNameOf(Node\Expr $expr): ?Name
    {
        if (! $expr instanceof Node\Expr\ClassConstFetch) {
            return null;
        }

        if (! $expr->name instanceof Node\Identifier || $expr->name->toString() !== 'class') {
            return null;
        }

        return $expr->class instanceof Name ? $expr->class : null;
    }

    /**
     * @see NameResolver::resolve()
     *
     * @param  array<string, string>  $importMap
     */
    private function resolveName(Name $name, ?string $namespace, array $importMap): string
    {
        return NameResolver::resolve($name, $namespace, $importMap);
    }

    /**
     * @see NameResolver::context()
     *
     * @param  array<int, Node>  $ast
     * @return array{0: ?string, 1: array<string, string>, 2: array<int, Node>}
     */
    private function resolveContext(array $ast): array
    {
        return NameResolver::context($ast);
    }

    private function unusable(
        bool $fileExists = true,
        bool $parseable = true,
        ?string $reason = null,
    ): DatabaseSeederAnalysis {
        return new DatabaseSeederAnalysis(
            fileExists: $fileExists,
            parseable: $parseable,
            className: null,
            namespace: null,
            extendsSeeder: false,
            hasRunMethod: false,
            wiredSeeders: [],
            usesWithoutModelEvents: false,
            unusualReason: $reason,
        );
    }
}
