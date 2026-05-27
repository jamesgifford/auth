<?php

declare(strict_types=1);

namespace Progravity\Auth\Installer;

use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

/**
 * AST-based modifier for the consumer's User model.
 *
 * Splits work into:
 *  - {@see analyze()} (read-only inspection),
 *  - {@see modify()} (produce a modification plan without touching disk),
 *  - {@see write()} (commit the modification, optionally backing up first),
 *  - {@see restore()} (roll back from .bak).
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
        } catch (\Throwable) {
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
                    if ($fqcn === 'Progravity\\Auth\\PublicId\\Concerns\\HasPublicId') {
                        $hasHasPublicIdTrait = true;
                    }
                    if ($fqcn === 'Progravity\\Auth\\Concerns\\HasAccounts') {
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
                "class does not extend Illuminate\\Foundation\\Auth\\User (extends %s)",
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
            $addedImports[] = 'Progravity\\Auth\\PublicId\\Concerns\\HasPublicId';
            $addedTraits[] = 'HasPublicId';
            $missingTraits[] = 'HasPublicId';
        }
        if (! $analysis->hasHasAccountsTrait) {
            $addedImports[] = 'Progravity\\Auth\\Concerns\\HasAccounts';
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
             * @param  array<int, Node\Stmt>  $stmts
             * @return array<int, Node\Stmt>
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
             * @param  array<int, Node\Stmt>  $bodyStmts
             * @return array<int, Node\Stmt>
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
                        ->addStmt(new Stmt\Return_(new Node\Scalar\String_('usr')))
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

    public function write(string $filePath, UserModelModification $modification, bool $createBackup = true): void
    {
        if ($createBackup) {
            file_put_contents($filePath.'.bak', $modification->originalCode);
        }

        file_put_contents($filePath, $modification->modifiedCode);
    }

    public function restore(string $filePath): void
    {
        $backupPath = $filePath.'.bak';
        if (! file_exists($backupPath)) {
            throw new RuntimeException("No backup file found at {$backupPath}.");
        }
        file_put_contents($filePath, (string) file_get_contents($backupPath));
    }

    /**
     * Mirror of the visitor's container processing for files without a
     * namespace declaration. Modifies $stmts (top-level) in place via the
     * same import/trait insertion rules.
     *
     * @param  array<int, Node\Stmt>  $stmts
     * @param  list<string>  $importsToAdd
     * @param  list<string>  $traitsToAdd
     * @return array<int, Node\Stmt>
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
                    ->addStmt(new Stmt\Return_(new Node\Scalar\String_('usr')))
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
