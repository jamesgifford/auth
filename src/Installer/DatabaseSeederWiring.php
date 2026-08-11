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
            hasUnusualStructure: ! $extendsSeeder || $runMethod === null,
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

        $namespace = $analysis->namespace;
        $importMap = $this->importMapOf($newStmts);

        foreach ($this->classNodesIn($newStmts) as $classNode) {
            $run = $this->findRunMethod($classNode);
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
            $lines[] = '        $this->call(\\'.$fqcn.'::class);';
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

        $namespace = $analysis->namespace;
        $importMap = $this->importMapOf($newStmts);

        $removalTraverser = new NodeTraverser;
        $removalTraverser->addVisitor($this->removalVisitor($namespace, $importMap));
        $newStmts = $removalTraverser->traverse($newStmts);

        // Second pass: drop control structures our removal emptied, and package
        // imports nothing references any more. Separate from the first pass
        // because emptiness can only be judged once the calls are gone.
        $cleanupTraverser = new NodeTraverser;
        $cleanupTraverser->addVisitor($this->cleanupVisitor());
        $newStmts = $cleanupTraverser->traverse($newStmts);

        return new DatabaseSeederChange(
            originalCode: $originalCode,
            modifiedCode: $this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens),
            addedSeeders: [],
            removedSeeders: $analysis->wiredSeeders,
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
        $position = 0;
        $foundSuccessor = false;

        foreach ($body as $index => $stmt) {
            foreach ($this->calledClassNames($stmt, $namespace, $importMap) as $name) {
                if (! isset($rank[$name])) {
                    continue;
                }
                if ($rank[$name] < $rank[$fqcn]) {
                    $position = $index + 1;
                } elseif (! $foundSuccessor) {
                    $position = $index;
                    $foundSuccessor = true;
                }
            }
        }

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
            public function __construct(
                private readonly Closure $isOurs,
                private readonly Closure $isThisCall,
            ) {}

            public function leaveNode(Node $node): ?int
            {
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
                        return NodeTraverser::REMOVE_NODE;
                    }

                    $value->items = $kept;

                    return null;
                }

                return ($this->isOurs)($value) ? NodeTraverser::REMOVE_NODE : null;
            }
        };
    }

    /**
     * Drops `if` statements our removal left with an empty body and no
     * elseif/else — genuinely dead code — and package seeder imports that
     * nothing references any more.
     */
    private function cleanupVisitor(): NodeVisitorAbstract
    {
        return new class extends NodeVisitorAbstract
        {
            public function leaveNode(Node $node): ?int
            {
                if ($node instanceof Stmt\If_
                    && $node->stmts === []
                    && $node->elseifs === []
                    && $node->else === null) {
                    return NodeTraverser::REMOVE_NODE;
                }

                if ($node instanceof Stmt\Use_) {
                    $kept = [];
                    foreach ($node->uses as $useItem) {
                        if (in_array($useItem->name->toString(), DatabaseSeederWiring::CANONICAL_ORDER, true)) {
                            continue;
                        }
                        $kept[] = $useItem;
                    }

                    if ($kept === []) {
                        return NodeTraverser::REMOVE_NODE;
                    }

                    $node->uses = $kept;
                }

                return null;
            }
        };
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
     * @param  array<int, Node>  $stmts
     * @return list<Stmt\Class_>
     */
    private function classNodesIn(array $stmts): array
    {
        [, , $scan] = $this->resolveContext($stmts);

        return array_values(array_filter(
            $scan,
            static fn (Node $stmt): bool => $stmt instanceof Stmt\Class_,
        ));
    }

    /**
     * @param  array<int, Node>  $stmts
     * @return array<string, string>
     */
    private function importMapOf(array $stmts): array
    {
        [, $importMap] = $this->resolveContext($stmts);

        return $importMap;
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

        $visit = function (Node $node) use (&$visit, &$found, $namespace, $importMap): void {
            if ($node instanceof Node\Expr\MethodCall && $this->isThisCall($node)) {
                foreach ($node->args as $arg) {
                    if (! $arg instanceof Node\Arg) {
                        continue;
                    }
                    foreach ($this->classRefsIn($arg->value) as $name) {
                        $found[] = $this->resolveName($name, $namespace, $importMap);
                    }
                }
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $subNode = $node->{$subNodeName};
                foreach (is_array($subNode) ? $subNode : [$subNode] as $child) {
                    if ($child instanceof Node) {
                        $visit($child);
                    }
                }
            }
        };

        $visit($root);

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
     * Resolve a name node to an FQCN using the file's namespace and imports —
     * the same resolution rules as UserModelModifier::analyze().
     *
     * @param  array<string, string>  $importMap
     */
    private function resolveName(Name $name, ?string $namespace, array $importMap): string
    {
        if ($name instanceof Name\FullyQualified || count($name->getParts()) > 1) {
            return $name->toString();
        }

        $short = $name->getLast();

        return $importMap[$short] ?? ($namespace !== null ? $namespace.'\\'.$short : $short);
    }

    /**
     * The file's namespace, its short-name => FQCN import map, and the
     * statement list the class lives in (inside the namespace when there is
     * one, otherwise the root). Accepts Node[] because that is what
     * NodeTraverser::traverse() returns; non-Stmt nodes simply never match.
     *
     * @param  array<int, Node>  $ast
     * @return array{0: ?string, 1: array<string, string>, 2: array<int, Node>}
     */
    private function resolveContext(array $ast): array
    {
        $namespace = null;
        $scan = $ast;

        foreach ($ast as $top) {
            if ($top instanceof Stmt\Namespace_) {
                $namespace = $top->name?->toString();
                $scan = $top->stmts;
                break;
            }
        }

        $importMap = [];
        foreach ($scan as $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $useItem) {
                    $short = $useItem->alias?->toString() ?? $useItem->name->getLast();
                    $importMap[$short] = $useItem->name->toString();
                }
            }
        }

        return [$namespace, $importMap, $scan];
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
            hasUnusualStructure: true,
            unusualReason: $reason,
        );
    }
}
