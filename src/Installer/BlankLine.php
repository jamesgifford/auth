<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use PhpParser\Comment;
use PhpParser\Node\Stmt;

/**
 * An empty line for the package's AST edits. php-parser has no node that
 * prints as a blank line, yet Laravel Pint's preset requires one between most
 * class members (class_attributes_separation) — so an edit that adds a
 * member without one fails the consuming app's lint check.
 *
 * {@see node()} is a Nop carrying a sentinel comment; {@see render()} turns
 * each into a true empty line once the AST has been printed.
 */
final class BlankLine
{
    private const SENTINEL = '// jamesgifford/auth:blank-line';

    public static function node(): Stmt\Nop
    {
        return new Stmt\Nop(['comments' => [new Comment(self::SENTINEL)]]);
    }

    /**
     * Collapse every sentinel into one empty line. The format-preserving
     * printer emits a Nop as its comment line followed by an indentation-only
     * line; the standard printer emits the comment line alone. Both forms
     * become a single line with no trailing whitespace.
     */
    public static function render(string $code): string
    {
        return preg_replace(
            '/^[ \t]*'.preg_quote(self::SENTINEL, '/').'(?:\R[ \t]*(?=\R))?/m',
            '',
            $code,
        ) ?? $code;
    }
}
