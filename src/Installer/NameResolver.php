<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * PHP name-resolution rules shared by every AST editor in the package
 * ({@see DatabaseSeederWiring}, {@see UserModelModifier}), so detection and
 * removal always agree on what a written name MEANS. One implementation keeps
 * the editors from diverging on aliases, group imports, or qualified names —
 * the divergence that previously let an alias-form call go undetected and be
 * wired twice.
 */
final class NameResolver
{
    /**
     * The file's namespace, its short-name => FQCN import map (including
     * group-use imports), and the statement list the class lives in (inside
     * the namespace when there is one, otherwise the root). Accepts Node[]
     * because that is what NodeTraverser::traverse() returns; non-Stmt nodes
     * simply never match.
     *
     * @param  array<int, Node>  $ast
     * @return array{0: ?string, 1: array<string, string>, 2: array<int, Node>}
     */
    public static function context(array $ast): array
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
            } elseif ($stmt instanceof Stmt\GroupUse) {
                foreach ($stmt->uses as $useItem) {
                    $short = $useItem->alias?->toString() ?? $useItem->name->getLast();
                    $importMap[$short] = $stmt->prefix->toString().'\\'.$useItem->name->toString();
                }
            }
        }

        return [$namespace, $importMap, $scan];
    }

    /**
     * Resolve a name node to an FQCN using PHP's name-resolution rules: a
     * fully-qualified name stands as written; anything else resolves its FIRST
     * segment against the imports (which is what makes a namespace alias like
     * `use ...\Database\Seeders as AuthSeeders;` work), falling back to the
     * current namespace.
     *
     * @param  array<string, string>  $importMap
     */
    public static function resolve(Name $name, ?string $namespace, array $importMap): string
    {
        if ($name instanceof Name\FullyQualified) {
            return $name->toString();
        }

        $parts = $name->getParts();
        $first = $parts[0];

        if (count($parts) > 1) {
            if (isset($importMap[$first])) {
                return $importMap[$first].'\\'.implode('\\', array_slice($parts, 1));
            }

            return $namespace !== null ? $namespace.'\\'.$name->toString() : $name->toString();
        }

        return $importMap[$first] ?? ($namespace !== null ? $namespace.'\\'.$first : $first);
    }
}
