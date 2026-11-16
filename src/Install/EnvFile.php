<?php

namespace NotificationChannels\Zapmizer\Install;

/**
 * Class EnvFile.
 *
 * The `.env` edits of `zapmizer:install`, in the shape of `reverb:install`:
 * a key that is already there (with any value, `export` or spaces around
 * the `=`) is never touched by `addMissing()`; the missing ones are
 * appended as a block at the end.
 */
final class EnvFile
{
    public function __construct(private string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function has(string $key): bool
    {
        return preg_match($this->pattern($key), $this->contents()) === 1;
    }

    /**
     * Append the keys the file does not have.
     *
     * @param array<string, string> $variables
     * @return array<int, string> the keys added
     */
    public function addMissing(array $variables): array
    {
        $contents = $this->contents();
        $missing = array_filter($variables, fn ($value, $key) => preg_match($this->pattern($key), $contents) !== 1, ARRAY_FILTER_USE_BOTH);

        if ($missing === []) {
            return [];
        }

        $block = implode("\n", array_map(fn ($key, $value) => $key . '=' . $this->format($value), array_keys($missing), $missing));
        $separator = $contents === '' ? '' : (str_ends_with($contents, "\n") ? "\n" : "\n\n");

        file_put_contents($this->path, $contents . $separator . $block . "\n");

        return array_keys($missing);
    }

    /**
     * Set the key to the value: replaces the line when the key is there with
     * another value, appends it when missing.
     *
     * @return bool whether the file changed
     */
    public function set(string $key, string $value): bool
    {
        $contents = $this->contents();
        $line = $key . '=' . $this->format($value);

        if (preg_match($this->pattern($key), $contents) !== 1) {
            return $this->addMissing([$key => $value]) !== [];
        }

        $updated = preg_replace('/^[ \t]*(?:export[ \t]+)?' . preg_quote($key, '/') . '[ \t]*=.*$/m', $line, $contents, 1);

        if ($updated === null || $updated === $contents) {
            return false;
        }

        file_put_contents($this->path, $updated);

        return true;
    }

    public function value(string $key): ?string
    {
        if (preg_match('/^[ \t]*(?:export[ \t]+)?' . preg_quote($key, '/') . '[ \t]*=[ \t]*(.*)$/m', $this->contents(), $match) !== 1) {
            return null;
        }

        return trim(trim($match[1]), '"\'');
    }

    private function contents(): string
    {
        return $this->exists() ? (string) file_get_contents($this->path) : '';
    }

    private function pattern(string $key): string
    {
        return '/^[ \t]*(?:export[ \t]+)?' . preg_quote($key, '/') . '[ \t]*=/m';
    }

    private function format(string $value): string
    {
        return $value === '' || preg_match('/^[A-Za-z0-9_.:\/@+-]+$/', $value) === 1
            ? $value
            : '"' . addcslashes($value, '"\\') . '"';
    }
}
