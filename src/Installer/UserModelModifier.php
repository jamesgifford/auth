<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use Closure;
use PhpParser\BuilderFactory;
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
 * AST-based modifier for the consumer's User model.
 *
 * Splits work into:
 *  - {@see analyze()} (read-only inspection),
 *  - {@see modify()} (plan the forward install modification, no disk writes),
 *  - {@see reverseModify()} (plan a surgical un-install removal, no disk writes),
 *  - {@see applyTransient()} (commit code with a transient backup: created
 *    before the edit, restored on failure, deleted on success — never orphaned).
 *
 * Uses nikic/php-parser's format-preserving printer so unchanged regions of
 * the file keep their original formatting (whitespace, comments, alignment).
 * New nodes (added imports, trait uses, the publicIdPrefix method) are
 * formatted by the Standard printer's defaults.
 */
final class UserModelModifier
{
    public function __construct(
        private readonly Parser $parser,
        private readonly Standard $printer,
        private readonly TransientFileWriter $writer,
    ) {}

    public function analyze(string $filePath): UserModelAnalysis
    {
        if (! file_exists($filePath)) {
            return $this->emptyAnalysis(fileExists: false);
        }

        $code = (string) file_get_contents($filePath);

        try {
            $ast = $this->parser->parse($code);
        } catch (Throwable) {
            return $this->emptyAnalysis(fileExists: true, parseable: false);
        }

        if ($ast === null) {
            return $this->emptyAnalysis(fileExists: true, parseable: false);
        }

        // Resolve namespace + imports, and locate class nodes.
        [$namespace, $importMap, $stmtsToScan] = NameResolver::context($ast);

        $classNodes = [];
        foreach ($stmtsToScan as $stmt) {
            if ($stmt instanceof Stmt\Class_) {
                $classNodes[] = $stmt;
            }
        }

        if (count($classNodes) === 0) {
            return $this->emptyAnalysis(
                fileExists: true,
                parseable: true,
                unusual: true,
                unusualReason: 'no class declaration found in the file',
            );
        }

        if (count($classNodes) > 1) {
            return $this->emptyAnalysis(
                fileExists: true,
                parseable: true,
                unusual: true,
                unusualReason: 'multiple class declarations found in a single file',
            );
        }

        /** @var Stmt\Class_ $classNode */
        $classNode = $classNodes[0];
        $className = $classNode->name?->toString();

        $extendsAuthenticatable = $classNode->extends !== null
            && NameResolver::resolve($classNode->extends, $namespace, $importMap) === 'Illuminate\\Foundation\\Auth\\User';

        // Walk class body for trait uses and the publicIdPrefix method.
        $hasHasPublicIdTrait = false;
        $hasHasAccountsTrait = false;
        $hasPublicIdPrefixMethod = false;

        foreach ($classNode->stmts as $bodyStmt) {
            if ($bodyStmt instanceof Stmt\TraitUse) {
                foreach ($bodyStmt->traits as $traitName) {
                    $fqcn = NameResolver::resolve($traitName, $namespace, $importMap);
                    if ($fqcn === 'JamesGifford\\Auth\\PublicId\\Concerns\\HasPublicId') {
                        $hasHasPublicIdTrait = true;
                    }
                    if ($fqcn === 'JamesGifford\\Auth\\Concerns\\HasAccounts') {
                        $hasHasAccountsTrait = true;
                    }
                }
            } elseif ($bodyStmt instanceof Stmt\ClassMethod) {
                if ($bodyStmt->name->toString() === 'publicIdPrefix') {
                    $hasPublicIdPrefixMethod = true;
                }
            }
        }

        $unusual = ! $extendsAuthenticatable;
        $unusualReason = $unusual
            ? sprintf(
                'class does not extend Illuminate\\Foundation\\Auth\\User (extends %s)',
                $classNode->extends?->toString() ?? '<none>',
            )
            : null;

        return new UserModelAnalysis(
            fileExists: true,
            parseable: true,
            className: $className,
            namespace: $namespace,
            extendsAuthenticatable: $extendsAuthenticatable,
            hasHasPublicIdTrait: $hasHasPublicIdTrait,
            hasHasAccountsTrait: $hasHasAccountsTrait,
            hasPublicIdPrefixMethod: $hasPublicIdPrefixMethod,
            hasUnusualStructure: $unusual,
            unusualReason: $unusualReason,
        );
    }

    public function modify(string $filePath, UserModelAnalysis $analysis): UserModelModification
    {
        if (! $analysis->isModifiable()) {
            throw new RuntimeException(
                'Cannot modify User model: '.($analysis->unusualReason ?? 'unknown reason')
            );
        }

        $originalCode = (string) file_get_contents($filePath);

        $oldStmts = $this->parser->parse($originalCode);
        $oldTokens = $this->parser->getTokens();

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new CloningVisitor);
        /** @var array<int, Stmt> $newStmts top-level statements of a parsed file */
        $newStmts = $traverser->traverse($oldStmts);

        $addedImports = [];
        $addedTraits = [];
        $missingTraits = [];
        if (! $analysis->hasHasPublicIdTrait) {
            $addedImports[] = 'JamesGifford\\Auth\\PublicId\\Concerns\\HasPublicId';
            $addedTraits[] = 'HasPublicId';
            $missingTraits[] = 'HasPublicId';
        }
        if (! $analysis->hasHasAccountsTrait) {
            $addedImports[] = 'JamesGifford\\Auth\\Concerns\\HasAccounts';
            $addedTraits[] = 'HasAccounts';
            $missingTraits[] = 'HasAccounts';
        }
        $addPrefixMethod = ! $analysis->hasPublicIdPrefixMethod;

        // The imports and class live in the namespace body when there is
        // one, otherwise in the file's root statements.
        $namespace = null;
        foreach ($newStmts as $top) {
            if ($top instanceof Stmt\Namespace_) {
                $namespace = $top;
                break;
            }
        }

        if ($namespace !== null) {
            $namespace->stmts = $this->editContainer($namespace->stmts, $addedImports, $missingTraits, $addPrefixMethod);
        } else {
            $newStmts = $this->editContainer($newStmts, $addedImports, $missingTraits, $addPrefixMethod);
        }

        $modifiedCode = BlankLine::render($this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens));

        return new UserModelModification(
            originalCode: $originalCode,
            modifiedCode: $modifiedCode,
            addedImports: $addedImports,
            addedTraits: $addedTraits,
            addedPublicIdPrefixMethod: $addPrefixMethod,
        );
    }

    /**
     * Plan a SURGICAL reverse-modification: remove ONLY the package's additions
     * (the HasPublicId/HasAccounts imports + trait usage, and the
     * publicIdPrefix() method), preserving every other trait, method, and line.
     *
     * This is the un-install counterpart to {@see modify()}. It does NOT restore
     * from a .bak — a stale backup would clobber the consumer's later edits;
     * surgical removal is the mechanism.
     */
    public function reverseModify(string $filePath, UserModelAnalysis $analysis): UserModelReversion
    {
        if (! $analysis->isModifiable()) {
            throw new RuntimeException(
                'Cannot reverse-modify User model: '.($analysis->unusualReason ?? 'unknown reason')
            );
        }

        $originalCode = (string) file_get_contents($filePath);

        $oldStmts = $this->parser->parse($originalCode);
        $oldTokens = $this->parser->getTokens();

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new CloningVisitor);
        /** @var array<int, Stmt> $newStmts top-level statements of a parsed file */
        $newStmts = $traverser->traverse($oldStmts);

        [$namespace, $importMap] = $this->resolveContext($newStmts);

        $visitor = new class($namespace, $importMap) extends NodeVisitorAbstract
        {
            /** @var list<string> */
            public array $removedImports = [];

            /** @var list<string> */
            public array $removedTraits = [];

            public bool $removedMethod = false;

            public ?string $removedReturnValue = null;

            public bool $removedCustomized = false;

            private const PACKAGE_FQCNS = [
                'JamesGifford\\Auth\\PublicId\\Concerns\\HasPublicId',
                'JamesGifford\\Auth\\Concerns\\HasAccounts',
            ];

            /**
             * @param  array<string, string>  $importMap  short name => FQCN
             */
            public function __construct(
                private readonly ?string $namespace,
                private readonly array $importMap,
            ) {}

            public function leaveNode(Node $node): int|Node|null
            {
                if ($node instanceof Stmt\Use_) {
                    $kept = [];
                    foreach ($node->uses as $useItem) {
                        if (in_array($useItem->name->toString(), self::PACKAGE_FQCNS, true)) {
                            $this->removedImports[] = $useItem->name->toString();
                        } else {
                            $kept[] = $useItem;
                        }
                    }
                    if ($kept === []) {
                        return NodeTraverser::REMOVE_NODE;
                    }
                    $node->uses = $kept;

                    return $node;
                }

                if ($node instanceof Stmt\TraitUse) {
                    $kept = [];
                    foreach ($node->traits as $traitName) {
                        if ($this->isPackageTrait($traitName)) {
                            $this->removedTraits[] = $traitName->getLast();
                        } else {
                            $kept[] = $traitName;
                        }
                    }
                    if ($kept === []) {
                        return NodeTraverser::REMOVE_NODE;
                    }
                    $node->traits = $kept;

                    return $node;
                }

                if ($node instanceof Stmt\ClassMethod && $node->name->toString() === 'publicIdPrefix') {
                    $this->captureRemovedMethod($node);
                    $this->removedMethod = true;

                    return NodeTraverser::REMOVE_NODE;
                }

                return null;
            }

            private function isPackageTrait(Name $name): bool
            {
                return in_array(
                    NameResolver::resolve($name, $this->namespace, $this->importMap),
                    self::PACKAGE_FQCNS,
                    true,
                );
            }

            private function captureRemovedMethod(Stmt\ClassMethod $method): void
            {
                $stmts = $method->stmts ?? [];
                if (count($stmts) === 1
                    && $stmts[0] instanceof Stmt\Return_
                    && $stmts[0]->expr instanceof Node\Scalar\String_
                ) {
                    $this->removedReturnValue = $stmts[0]->expr->value;
                    $this->removedCustomized = false;

                    return;
                }

                // The body is not the plain install-generated `return '<prefix>';`
                // — treat it as a consumer customization worth flagging.
                $this->removedReturnValue = null;
                $this->removedCustomized = true;
            }
        };

        $removalTraverser = new NodeTraverser;
        $removalTraverser->addVisitor($visitor);
        $newStmts = $removalTraverser->traverse($newStmts);

        $modifiedCode = $this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        return new UserModelReversion(
            originalCode: $originalCode,
            modifiedCode: $modifiedCode,
            removedImports: $visitor->removedImports,
            removedTraits: $visitor->removedTraits,
            removedPublicIdPrefixMethod: $visitor->removedMethod,
            removedPrefixReturnValue: $visitor->removedReturnValue,
            removedPrefixWasCustomized: $visitor->removedCustomized,
        );
    }

    /**
     * Commit new code to the model with a TRANSIENT backup: copy the file to
     * .bak first, write, verify the result is valid PHP (plus an optional
     * caller check), then DELETE the .bak on success. On any failure the file
     * is restored from the backup and the .bak removed — so the model returns
     * to its exact pre-edit state and NO .bak is ever left behind.
     *
     * @param  ?Closure():void  $verify  Optional semantic check; should throw on failure.
     */
    public function applyTransient(string $filePath, string $newCode, ?Closure $verify = null): void
    {
        $this->writer->apply($filePath, $newCode, $verify);
    }

    /**
     * @see NameResolver::context()
     *
     * @param  array<int, Stmt>  $stmts
     * @return array{0: ?string, 1: array<string, string>}
     */
    private function resolveContext(array $stmts): array
    {
        [$namespace, $importMap] = NameResolver::context($stmts);

        return [$namespace, $importMap];
    }

    /**
     * Apply the forward modification to the statement container holding the
     * imports and the class (a namespace body, or the file's root). Every
     * addition is placed where the consuming app's Pint (laravel preset)
     * expects it, so installing never fails the app's lint check.
     *
     * @param  array<int, Stmt>  $stmts
     * @param  list<string>  $importsToAdd
     * @param  list<string>  $traitsToAdd
     * @return array<int, Stmt>
     */
    private function editContainer(array $stmts, array $importsToAdd, array $traitsToAdd, bool $addPrefixMethod): array
    {
        foreach ($importsToAdd as $fqcn) {
            $stmts = ImportList::insert($stmts, $fqcn);
        }

        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Stmt\Class_) {
                continue;
            }

            $body = $this->addTraits($stmt->stmts, $traitsToAdd);

            if ($addPrefixMethod) {
                if ($body !== []) {
                    $body[] = BlankLine::node();
                }

                $body[] = (new BuilderFactory)->method('publicIdPrefix')
                    ->makePublic()
                    ->setReturnType('string')
                    ->addStmt(new Stmt\Return_(new Node\Scalar\String_('user')))
                    ->getNode();
            }

            $stmt->stmts = $body;
        }

        return $stmts;
    }

    /**
     * Add traits in the order Pint's ordered_traits rule checks: each is
     * merged alphabetically into the class's existing trait use — Laravel's
     * own `use HasFactory, Notifiable;` style — choosing the last statement
     * whose leading trait sorts at or before it, so the statements stay
     * ordered by their first trait. A class with no mergeable trait use gets
     * a new, sorted statement at the top of its body.
     *
     * Uninstall's {@see reverseModify()} removes the names from whichever
     * statement holds them, so either shape reverses cleanly.
     *
     * @param  array<int, Stmt>  $body
     * @param  list<string>  $traits  short names, already imported
     * @return array<int, Stmt>
     */
    private function addTraits(array $body, array $traits): array
    {
        if ($traits === []) {
            return $body;
        }

        // A statement with an adaptation block (`use A { ... }`) is left alone.
        $uses = array_values(array_filter(
            $body,
            static fn (Stmt $stmt): bool => $stmt instanceof Stmt\TraitUse && $stmt->adaptations === [],
        ));

        if ($uses === []) {
            usort($traits, strcasecmp(...));

            $leading = [new Stmt\TraitUse(array_map(static fn (string $trait): Name => new Name($trait), $traits))];
            if ($body !== []) {
                $leading[] = BlankLine::node();
            }

            return [...$leading, ...$body];
        }

        foreach ($traits as $trait) {
            $target = $uses[0];
            foreach ($uses as $use) {
                if (strcasecmp($use->traits[0]->toString(), $trait) <= 0) {
                    $target = $use;
                }
            }

            $position = count($target->traits);
            foreach ($target->traits as $index => $existing) {
                if (strcasecmp($existing->toString(), $trait) > 0) {
                    $position = $index;
                    break;
                }
            }

            array_splice($target->traits, $position, 0, [new Name($trait)]);
        }

        return $body;
    }

    private function emptyAnalysis(
        bool $fileExists = true,
        bool $parseable = true,
        bool $unusual = false,
        ?string $unusualReason = null,
    ): UserModelAnalysis {
        return new UserModelAnalysis(
            fileExists: $fileExists,
            parseable: $parseable,
            className: null,
            namespace: null,
            extendsAuthenticatable: false,
            hasHasPublicIdTrait: false,
            hasHasAccountsTrait: false,
            hasPublicIdPrefixMethod: false,
            hasUnusualStructure: $unusual || ! $fileExists || ! $parseable,
            unusualReason: $unusualReason ?? ($fileExists && $parseable
                ? null
                : (! $fileExists ? 'file does not exist' : 'file is not parseable PHP')),
        );
    }
}
