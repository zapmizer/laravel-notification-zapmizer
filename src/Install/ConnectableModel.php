<?php

namespace NotificationChannels\Zapmizer\Install;

use ParseError;

/**
 * Class ConnectableModel.
 *
 * Adds `implements Connectable` and `use ConnectsZapmizer;` to a model file
 * for `zapmizer:install --model`. It reads the file's tokens, never runs it,
 * and only edits the shape it recognizes: one `namespace X;`, one
 * declaration of the class, imports at the top. Anything else — or an edit
 * that would not parse — leaves the file as it was, and the command prints
 * the snippet to paste instead (the way `install:api` handles a bootstrap
 * file it does not recognize).
 */
final class ConnectableModel
{
    public const INTERFACE = 'NotificationChannels\Zapmizer\Contracts\Connectable';

    public const TRAIT = 'NotificationChannels\Zapmizer\Connectable';

    public const TRAIT_ALIAS = 'ConnectsZapmizer';

    /** @var array<int, array{0: int|string, 1: string, 2: int}> type, text, offset */
    private array $tokens = [];

    /** @var array<string, string> short name (lower case) => fully qualified name */
    private array $imports = [];

    private ?int $namespaceEnd = null;

    private ?int $lastImportEnd = null;

    private ?int $classIndex = null;

    private ?int $braceIndex = null;

    public function __construct(private string $code, private string $shortName)
    {
        $this->read();
    }

    /**
     * Whether the file has the shape the edit needs.
     */
    public function recognized(): bool
    {
        return $this->namespaceEnd !== null && $this->classIndex !== null && $this->braceIndex !== null;
    }

    public function implementsInterface(): bool
    {
        if (!$this->recognized()) {
            return false;
        }

        $implementing = false;

        for ($i = $this->classIndex; $i < $this->braceIndex; $i++) {
            [$type, $text] = $this->tokens[$i];

            if ($type === T_IMPLEMENTS) {
                $implementing = true;
            } elseif ($implementing && $this->isName($type) && $this->resolve($text) === self::INTERFACE) {
                return true;
            }
        }

        return false;
    }

    public function usesTrait(): bool
    {
        if (!$this->recognized()) {
            return false;
        }

        foreach ($this->traitUses() as [$start, $end]) {
            for ($i = $start; $i < $end; $i++) {
                [$type, $text] = $this->tokens[$i];

                if ($this->isName($type) && $this->resolve($text) === self::TRAIT) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The file with the interface and the trait, or null when the shape is
     * not recognized or the result would not parse.
     */
    public function withConnectable(): ?string
    {
        if (!$this->recognized()) {
            return null;
        }

        $interfaceName = $this->interfaceName();
        $traitName = $this->traitName();

        if ($interfaceName === null || $traitName === null) {
            return null;
        }

        /** @var array<int, array{0: int, 1: string}> offset => text, applied from the end */
        $inserts = [];
        $importLines = [];

        if (!$this->usesTrait()) {
            $inserts[] = $this->traitInsert($traitName);

            if (!isset($this->imports[strtolower($traitName)])) {
                $importLines[] = 'use ' . self::TRAIT . ' as ' . $traitName . ';';
            }
        }

        if (!$this->implementsInterface()) {
            $inserts[] = $this->implementsInsert($interfaceName);

            if (!isset($this->imports[strtolower($interfaceName)])) {
                $importLines[] = $interfaceName === 'Connectable'
                    ? 'use ' . self::INTERFACE . ';'
                    : 'use ' . self::INTERFACE . ' as ' . $interfaceName . ';';
            }
        }

        if ($inserts === []) {
            return $this->code;
        }

        if ($importLines !== []) {
            sort($importLines);
            $at = $this->lastImportEnd ?? $this->namespaceEnd;
            $prefix = $this->lastImportEnd === null ? "\n" : '';
            $inserts[] = [$at, "\n" . $prefix . implode("\n", $importLines)];
        }

        usort($inserts, fn ($a, $b) => $b[0] <=> $a[0]);

        $code = $this->code;

        foreach ($inserts as [$offset, $text]) {
            $code = substr($code, 0, $offset) . $text . substr($code, $offset);
        }

        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (ParseError) {
            return null;
        }

        return $code;
    }

    /**
     * What to paste by hand when the file is not recognized.
     */
    public static function snippet(string $shortName): string
    {
        return implode("\n", [
            'use ' . self::INTERFACE . ';',
            'use ' . self::TRAIT . ' as ' . self::TRAIT_ALIAS . ';',
            '',
            "class {$shortName} extends ... implements Connectable",
            '{',
            '    use ' . self::TRAIT_ALIAS . ';',
            '    // ...',
            '}',
        ]);
    }

    private function read(): void
    {
        $offset = 0;

        foreach (token_get_all($this->code) as $token) {
            $type = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            $this->tokens[] = [$type, $text, $offset];
            $offset += strlen($text);
        }

        $namespaces = 0;
        $declarations = [];
        $depth = 0;

        foreach ($this->tokens as $i => [$type, $text]) {
            if ($text === '{' || $type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }

            if ($depth !== 0) {
                continue;
            }

            if ($type === T_NAMESPACE && $this->isName($this->tokens[$this->next($i)][0] ?? null)) {
                $namespaces++;
                $end = $this->find($i, ';', '{');
                $this->namespaceEnd = $end !== null && $this->tokens[$end][1] === ';' ? $this->tokens[$end][2] + 1 : null;
            } elseif ($type === T_USE && $this->classIndex === null && $declarations === []) {
                $end = $this->find($i, ';');

                if ($end !== null) {
                    $this->import($i, $end);
                    $this->lastImportEnd = $this->tokens[$end][2] + 1;
                }
            } elseif ($type === T_CLASS) {
                $previous = $this->tokens[$this->previous($i)][0] ?? null;
                $name = $this->tokens[$this->next($i)] ?? null;

                if ($previous !== T_DOUBLE_COLON && $previous !== T_NEW && $name !== null && $name[0] === T_STRING) {
                    $declarations[] = [$i, $name[1]];
                }
            }
        }

        $matching = array_values(array_filter($declarations, fn ($declaration) => strcasecmp($declaration[1], $this->shortName) === 0));

        if ($namespaces !== 1 || count($declarations) !== 1 || count($matching) !== 1) {
            $this->namespaceEnd = null;

            return;
        }

        $this->classIndex = $matching[0][0];
        $this->braceIndex = $this->find($this->classIndex, '{');
    }

    /**
     * Record the names a top-level `use` imports. A function/const import
     * or a group import marks the names it may bring as taken.
     */
    private function import(int $start, int $end): void
    {
        $text = '';

        for ($i = $start + 1; $i < $end; $i++) {
            $text .= $this->tokens[$i][1];
        }

        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (str_starts_with($text, 'function ') || str_starts_with($text, 'const ')) {
            return;
        }

        if (str_contains($text, '{')) {
            preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*(?:,|}|$)/', $text, $matches);

            foreach ($matches[1] as $name) {
                $this->imports[strtolower($name)] = '?' . $name;
            }

            return;
        }

        foreach (explode(',', $text) as $clause) {
            $parts = preg_split('/\s+as\s+/i', trim($clause));
            $name = ltrim($parts[0], '\\');
            $alias = $parts[1] ?? substr($name, (int) strrpos('\\' . $name, '\\'));
            $this->imports[strtolower(trim($alias))] = $name;
        }
    }

    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = strtolower(explode('\\', $name)[0]);

        if (!isset($this->imports[$first])) {
            return $name;
        }

        $rest = substr($name, strlen($first));

        return $this->imports[$first] . $rest;
    }

    /**
     * `Connectable` for the interface, as the docs write it, unless the name
     * is taken by something else — then `ZapmizerConnectable`.
     */
    private function interfaceName(): ?string
    {
        foreach (['Connectable', 'ZapmizerConnectable'] as $name) {
            if ($this->nameFree($name, self::INTERFACE)) {
                return $name;
            }
        }

        return null;
    }

    private function traitName(): ?string
    {
        return $this->nameFree(self::TRAIT_ALIAS, self::TRAIT) ? self::TRAIT_ALIAS : null;
    }

    private function nameFree(string $name, string $for): bool
    {
        $imported = $this->imports[strtolower($name)] ?? null;

        if ($imported !== null) {
            return $imported === $for;
        }

        return strcasecmp($name, $this->shortName) !== 0;
    }

    /**
     * After the first trait `use ...;` of the class body, or right after the
     * opening brace when there is none (or it has an adaptation block).
     *
     * @return array{0: int, 1: string}
     */
    private function traitInsert(string $traitName): array
    {
        $indent = $this->bodyIndent();
        $uses = $this->traitUses();

        if ($uses !== [] && $this->tokens[$uses[0][1]][1] === ';') {
            return [$this->tokens[$uses[0][1]][2] + 1, "\n{$indent}use {$traitName};"];
        }

        return [$this->tokens[$this->braceIndex][2] + 1, "\n{$indent}use {$traitName};"];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function implementsInsert(string $interfaceName): array
    {
        $hasImplements = false;

        for ($i = $this->classIndex; $i < $this->braceIndex; $i++) {
            $hasImplements = $hasImplements || $this->tokens[$i][0] === T_IMPLEMENTS;
        }

        $last = $this->previous($this->braceIndex);
        $at = $this->tokens[$last][2] + strlen($this->tokens[$last][1]);

        return [$at, $hasImplements ? ", {$interfaceName}" : " implements {$interfaceName}"];
    }

    /**
     * The trait `use` statements at the class body's top level, as
     * [first token, terminating token] pairs.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function traitUses(): array
    {
        $uses = [];
        $depth = 0;

        for ($i = $this->braceIndex + 1; $i < count($this->tokens); $i++) {
            [$type, $text] = $this->tokens[$i];

            if ($text === '{' || $type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($text === '}') {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            } elseif ($depth === 0 && $type === T_USE) {
                $end = $this->find($i, ';', '{');

                if ($end !== null) {
                    $uses[] = [$i, $end];
                }
            }
        }

        return $uses;
    }

    private function bodyIndent(): string
    {
        $after = substr($this->code, $this->tokens[$this->braceIndex][2] + 1);

        return preg_match('/\n([ \t]+)\S/', $after, $match) === 1 ? $match[1] : '    ';
    }

    private function isName(mixed $type): bool
    {
        return in_array($type, array_filter([
            T_STRING,
            T_NS_SEPARATOR,
            defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : null,
            defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : null,
        ]), true);
    }

    private function find(int $from, string ...$texts): ?int
    {
        for ($i = $from + 1; $i < count($this->tokens); $i++) {
            if (in_array($this->tokens[$i][1], $texts, true) && !is_int($this->tokens[$i][0])) {
                return $i;
            }
        }

        return null;
    }

    private function next(int $index): int
    {
        do {
            $index++;
        } while (isset($this->tokens[$index]) && in_array($this->tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));

        return $index;
    }

    private function previous(int $index): int
    {
        do {
            $index--;
        } while ($index > 0 && in_array($this->tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));

        return $index;
    }
}
