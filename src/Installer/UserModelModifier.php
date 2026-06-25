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

        // Resolve namespace + locate class nodes.
        $namespace = null;
        $classNodes = [];
        $importMap = [];   // short name => FQCN
        $stmtsToScan = $ast;

        foreach ($ast as $top) {
            if ($top instanceof Stmt\Namespace_) {
                $namespace = $top->name?->toString();
                $stmtsToScan = $top->stmts;
                break;
            }
        }

        foreach ($stmtsToScan as $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $useItem) {
                    $short = $useItem->alias?->toString() ?? $useItem->name->getLast();
                    $importMap[$short] = $useItem->name->toString();
                }
            } elseif ($stmt instanceof Stmt\Class_) {
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

        $extendsAuthenticatable = false;
        if ($classNode->extends !== null) {
            $parentShort = $classNode->extends->getLast();
            $parentFqcn = $importMap[$parentShort] ?? ($namespace !== null ? $namespace.'\\'.$parentShort : $parentShort);
            // If the extends name is multi-part, prefer its own resolution.
            if (count($classNode->extends->getParts()) > 1) {
                $parentFqcn = $classNode->extends->toString();
            }
            $extendsAuthenticatable = $parentFqcn === 'Illuminate\\Foundation\\Auth\\User';
        }

        // Walk class body for trait uses and the publicIdPrefix method.
        $hasHasPublicIdTrait = false;
        $hasHasAccountsTrait = false;
        $hasPublicIdPrefixMethod = false;

        foreach ($classNode->stmts as $bodyStmt) {
            if ($bodyStmt instanceof Stmt\TraitUse) {
                foreach ($bodyStmt->traits as $traitName) {
                    $short = $traitName->getLast();
                    $fqcn = $importMap[$short] ?? ($namespace !== null ? $namespace.'\\'.$short : $short);
                    if (count($traitName->getParts()) > 1) {
                        $fqcn = $traitName->toString();
                    }
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

        $visitor = new class($addedImports, $missingTraits, $addPrefixMethod) extends NodeVisitorAbstract
        {
            /**
             * @param  list<string>  $importsToAdd
             * @param  list<string>  $traitsToAdd
             */
            public function __construct(
                private readonly array $importsToAdd,
                private readonly array $traitsToAdd,
                private readonly bool $addPrefixMethod,
            ) {}

            public function enterNode(Node $node): ?Node
            {
                // Container that holds the use statements + class. Could be
                // a Namespace_ or the root statement list.
                if ($node instanceof Stmt\Namespace_) {
                    $node->stmts = $this->processContainer($node->stmts);
                }

                return null;
            }

            /**
             * @param  array<int, Stmt>  $stmts
             * @return array<int, Stmt>
             */
            private function processContainer(array $stmts): array
            {
                // Insert new use statements after the last existing one.
                $lastUseIndex = -1;
                foreach ($stmts as $i => $stmt) {
                    if ($stmt instanceof Stmt\Use_) {
                        $lastUseIndex = $i;
                    }
                }

                $newUseStmts = [];
                foreach ($this->importsToAdd as $fqcn) {
                    $newUseStmts[] = new Stmt\Use_([
                        new Node\UseItem(new Name($fqcn)),
                    ]);
                }

                if ($newUseStmts !== []) {
                    if ($lastUseIndex === -1) {
                        $stmts = array_merge($newUseStmts, $stmts);
                    } else {
                        array_splice($stmts, $lastUseIndex + 1, 0, $newUseStmts);
                    }
                }

                // Mutate the class node in place.
                foreach ($stmts as $stmt) {
                    if ($stmt instanceof Stmt\Class_) {
                        $stmt->stmts = $this->processClassBody($stmt->stmts);
                    }
                }

                return $stmts;
            }

            /**
             * @param  array<int, Stmt>  $bodyStmts
             * @return array<int, Stmt>
             */
            private function processClassBody(array $bodyStmts): array
            {
                // Insert a fresh `use TraitA, TraitB;` line below the last
                // existing class-level trait use, or at the top of the body.
                $lastTraitIndex = -1;
                foreach ($bodyStmts as $i => $stmt) {
                    if ($stmt instanceof Stmt\TraitUse) {
                        $lastTraitIndex = $i;
                    }
                }

                if ($this->traitsToAdd !== []) {
                    $traitNames = array_map(
                        fn (string $short): Name => new Name($short),
                        $this->traitsToAdd
                    );
                    $newTraitUse = new Stmt\TraitUse($traitNames);

                    if ($lastTraitIndex === -1) {
                        array_unshift($bodyStmts, $newTraitUse);
                    } else {
                        array_splice($bodyStmts, $lastTraitIndex + 1, 0, [$newTraitUse]);
                    }
                }

                if ($this->addPrefixMethod) {
                    $factory = new BuilderFactory;
                    $method = $factory->method('publicIdPrefix')
                        ->makePublic()
                        ->setReturnType('string')
                        ->addStmt(new Stmt\Return_(new Node\Scalar\String_('user')))
                        ->getNode();
                    $bodyStmts[] = $method;
                }

                return $bodyStmts;
            }
        };

        $modifyTraverser = new NodeTraverser;
        $modifyTraverser->addVisitor($visitor);
        $newStmts = $modifyTraverser->traverse($newStmts);

        // If the file had no namespace, the container we mutated lives at
        // the root — process it directly here.
        $hasNamespace = false;
        foreach ($newStmts as $top) {
            if ($top instanceof Stmt\Namespace_) {
                $hasNamespace = true;
                break;
            }
        }
        if (! $hasNamespace) {
            $newStmts = $this->processRootContainer($newStmts, $addedImports, $missingTraits, $addPrefixMethod);
        }

        $modifiedCode = $this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

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
                $short = $name->getLast();
                $fqcn = $this->importMap[$short] ?? ($this->namespace !== null ? $this->namespace.'\\'.$short : $short);
                if (count($name->getParts()) > 1) {
                    $fqcn = $name->toString();
                }

                return in_array($fqcn, self::PACKAGE_FQCNS, true);
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
        $backupPath = $filePath.'.bak';
        copy($filePath, $backupPath);

        try {
            file_put_contents($filePath, $newCode);

            // Validity gate: the written file must still parse as PHP.
            $written = (string) file_get_contents($filePath);
            if ($this->parser->parse($written) === null) {
                throw new RuntimeException('the edited User model did not parse as valid PHP');
            }

            if ($verify !== null) {
                $verify();
            }
        } catch (Throwable $e) {
            if (is_file($backupPath)) {
                file_put_contents($filePath, (string) file_get_contents($backupPath));
            }
            @unlink($backupPath);

            throw $e;
        }

        @unlink($backupPath);
    }

    /**
     * Resolve the file's namespace and short-name => FQCN import map from a
     * parsed statement list (mirrors the first half of {@see analyze()}).
     *
     * @param  array<int, Stmt>  $stmts
     * @return array{0: ?string, 1: array<string, string>}
     */
    private function resolveContext(array $stmts): array
    {
        $namespace = null;
        $importMap = [];
        $scan = $stmts;

        foreach ($stmts as $top) {
            if ($top instanceof Stmt\Namespace_) {
                $namespace = $top->name?->toString();
                $scan = $top->stmts;
                break;
            }
        }

        foreach ($scan as $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $useItem) {
                    $short = $useItem->alias?->toString() ?? $useItem->name->getLast();
                    $importMap[$short] = $useItem->name->toString();
                }
            }
        }

        return [$namespace, $importMap];
    }

    /**
     * Mirror of the visitor's container processing for files without a
     * namespace declaration. Modifies $stmts (top-level) in place via the
     * same import/trait insertion rules.
     *
     * @param  array<int, Stmt>  $stmts
     * @param  list<string>  $importsToAdd
     * @param  list<string>  $traitsToAdd
     * @return array<int, Stmt>
     */
    private function processRootContainer(array $stmts, array $importsToAdd, array $traitsToAdd, bool $addPrefixMethod): array
    {
        $lastUseIndex = -1;
        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                $lastUseIndex = $i;
            }
        }

        $newUseStmts = [];
        foreach ($importsToAdd as $fqcn) {
            $newUseStmts[] = new Stmt\Use_([new Node\UseItem(new Name($fqcn))]);
        }
        if ($newUseStmts !== []) {
            if ($lastUseIndex === -1) {
                $stmts = array_merge($newUseStmts, $stmts);
            } else {
                array_splice($stmts, $lastUseIndex + 1, 0, $newUseStmts);
            }
        }

        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Stmt\Class_) {
                continue;
            }

            $bodyStmts = $stmt->stmts;
            $lastTraitIndex = -1;
            foreach ($bodyStmts as $i => $bs) {
                if ($bs instanceof Stmt\TraitUse) {
                    $lastTraitIndex = $i;
                }
            }
            if ($traitsToAdd !== []) {
                $traitNames = array_map(fn (string $s): Name => new Name($s), $traitsToAdd);
                $newTraitUse = new Stmt\TraitUse($traitNames);
                if ($lastTraitIndex === -1) {
                    array_unshift($bodyStmts, $newTraitUse);
                } else {
                    array_splice($bodyStmts, $lastTraitIndex + 1, 0, [$newTraitUse]);
                }
            }
            if ($addPrefixMethod) {
                $factory = new BuilderFactory;
                $method = $factory->method('publicIdPrefix')
                    ->makePublic()
                    ->setReturnType('string')
                    ->addStmt(new Stmt\Return_(new Node\Scalar\String_('user')))
                    ->getNode();
                $bodyStmts[] = $method;
            }
            $stmt->stmts = $bodyStmts;
        }

        return $stmts;
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
