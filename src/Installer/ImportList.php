<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;

/**
 * Adds class imports to a consumer's file where Laravel Pint's preset expects
 * them, so the package's edits never fail the consuming app's lint check: in
 * alphabetical order ({@see compare()}), with class imports ahead of function
 * imports.
 */
final class ImportList
{
    /**
     * Insert `use $fqcn;` into a statement container — a namespace body, or a
     * file's root statements — before the first class import that sorts after
     * it, else after the last class import. Existing imports are never
     * reordered: if they were sorted, the result still is.
     *
     * A container with no imports at all gets the new one ahead of its first
     * statement (after any declare()), followed by a blank line.
     *
     * @param  array<int, Stmt>  $stmts
     * @return array<int, Stmt>
     */
    public static function insert(array $stmts, string $fqcn): array
    {
        $import = new Stmt\Use_([new UseItem(new Name($fqcn))]);

        $sortsAfter = null;
        $lastClassImport = null;
        $firstFunctionImport = null;
        $firstImport = null;
        $lastImport = null;

        foreach (array_values($stmts) as $index => $stmt) {
            if (! $stmt instanceof Stmt\Use_ && ! $stmt instanceof Stmt\GroupUse) {
                continue;
            }

            $firstImport ??= $index;
            $lastImport = $index;

            if ($stmt instanceof Stmt\Use_ && $stmt->type === Stmt\Use_::TYPE_NORMAL) {
                $lastClassImport = $index;
                if ($sortsAfter === null && self::compare(self::sortKey($stmt), $fqcn) > 0) {
                    $sortsAfter = $index;
                }
            } elseif ($stmt->type === Stmt\Use_::TYPE_FUNCTION) {
                $firstFunctionImport ??= $index;
            }
        }

        $stmts = array_values($stmts);

        if ($lastImport === null) {
            $first = 0;
            while (isset($stmts[$first]) && $stmts[$first] instanceof Stmt\Declare_) {
                $first++;
            }

            array_splice($stmts, $first, 0, [$import, BlankLine::node()]);

            return $stmts;
        }

        $position = $sortsAfter
            ?? ($lastClassImport !== null ? $lastClassImport + 1 : null)
            ?? $firstFunctionImport
            ?? $lastImport + 1;

        // Inserted ahead of an original first import while another statement
        // precedes the imports (a root-level declare()), php-parser's
        // format-preserving printer would butt the new import against that
        // statement and leave the original blank line trailing it. Replacing
        // the displaced import with a blank line, the new import, and a fresh
        // copy of itself prints the block as one.
        if ($position === $firstImport && $position > 0 && $stmts[$position]->hasAttribute('origNode')) {
            $displaced = clone $stmts[$position];
            $displaced->setAttributes(['comments' => $stmts[$position]->getComments()]);

            array_splice($stmts, $position, 1, [BlankLine::node(), $import, $displaced]);

            return $stmts;
        }

        array_splice($stmts, $position, 0, [$import]);

        return $stmts;
    }

    /**
     * The short name to refer to $fqcn by: the alias it is already imported
     * under, else its basename when importing that is safe. Null when the
     * basename is already taken — imported as another class, used unqualified
     * for a same-namespace class (an import would silently repoint it), or
     * declared in this file — so the name must be written out in full.
     *
     * @param  array<int, Stmt>  $stmts  the container {@see insert()} would edit
     * @param  array<string, string>  $importMap  short name => FQCN
     */
    public static function aliasFor(string $fqcn, array $stmts, array $importMap): ?string
    {
        foreach ($importMap as $alias => $imported) {
            if (strcasecmp($imported, $fqcn) === 0) {
                return $alias;
            }
        }

        $short = class_basename($fqcn);

        foreach (array_keys($importMap) as $alias) {
            if (strcasecmp($alias, $short) === 0) {
                return null;
            }
        }

        $finder = new NodeFinder;

        foreach ($finder->findInstanceOf($stmts, Name::class) as $name) {
            if ($name->isUnqualified() && strcasecmp($name->toString(), $short) === 0) {
                return null;
            }
        }

        foreach ($finder->findInstanceOf($stmts, Stmt\ClassLike::class) as $class) {
            if ($class->name !== null && strcasecmp($class->name->toString(), $short) === 0) {
                return null;
            }
        }

        return $short;
    }

    /**
     * Pint's alphabetical order (php-cs-fixer's ordered_imports with the
     * "alpha" algorithm, as the laravel preset configures it): case-
     * insensitive, with namespace separators compared as spaces so `Foo\Bar`
     * sorts before `FooBar`.
     */
    public static function compare(string $a, string $b): int
    {
        return strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b));
    }

    /**
     * What Pint compares an import by: the imported name, plus its alias.
     */
    private static function sortKey(Stmt\Use_ $stmt): string
    {
        $item = $stmt->uses[0];

        return $item->name->toString().($item->alias !== null ? ' as '.$item->alias->toString() : '');
    }
}
