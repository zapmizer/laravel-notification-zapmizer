<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The files `zapmizer:install --inbox` copies to the app's resources/js/zapmizer.
 * They bring no look of their own: the app styles them, through the hook classes.
 */
class PublishedComponentsTest extends TestCase
{
    private const STUBS = __DIR__ . '/../../stubs';

    /**
     * @return iterable<string, array{string}>
     */
    public static function components(): iterable
    {
        foreach (glob(self::STUBS . '/inertia-vue*/*.vue') as $file) {
            yield basename($file) => [$file];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publishedFiles(): iterable
    {
        foreach (glob(self::STUBS . '/inertia-vue*/*.{vue,ts}', GLOB_BRACE) as $file) {
            yield basename($file) => [$file];
        }
    }

    /**
     * @dataProvider components
     */
    #[DataProvider('components')]
    public function testNoComponentCarriesADesignSystemClass(string $file)
    {
        $source = file_get_contents($file);

        $this->assertDoesNotMatchRegularExpression('/(?<![\w-])gp-/', $source, 'no gp-* class of the app the components came from');
        $this->assertDoesNotMatchRegularExpression(
            '/[\'"`\s](?:hover:|disabled:)?(?:bg|text|border)-[\w\[\]\/.%-]+/',
            $this->classValues($source),
            'no Tailwind color/typography utility',
        );
    }

    /**
     * @dataProvider components
     */
    #[DataProvider('components')]
    public function testEveryStaticClassIsAPrefixedHook(string $file)
    {
        preg_match_all('/(?<![:\w-])class="([^"]*)"/', file_get_contents($file), $matches);

        foreach ($matches[1] as $value) {
            foreach (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) as $class) {
                $this->assertMatchesRegularExpression('/^zapmizer-[a-z]+(?:-[a-z]+)*(?:__[a-z]+(?:-[a-z]+)*)?(?:--[a-z]+(?:-[a-z]+)*)?$/', $class, basename($file));
            }
        }
    }

    public function testThePanelStatusIsAHookModifier()
    {
        $panel = file_get_contents(self::STUBS . '/inertia-vue/ConnectionPanel.vue');

        $this->assertStringContainsString('class="zapmizer-panel__status"', $panel);

        preg_match_all("/tone: '([^']+)'/", $panel, $tones);

        $this->assertNotEmpty($tones[1]);

        foreach (array_unique($tones[1]) as $tone) {
            $this->assertMatchesRegularExpression('/^zapmizer-panel__status--[a-z]+$/', $tone);
        }
    }

    /**
     * @dataProvider components
     */
    #[DataProvider('components')]
    public function testNoComponentShipsCss(string $file)
    {
        $this->assertStringNotContainsString('<style', file_get_contents($file));
    }

    /**
     * The app's lint (prettier with 4 spaces, printWidth and vue/max-len 120)
     * runs over resources/js, and the published files land there.
     *
     * @dataProvider publishedFiles
     */
    #[DataProvider('publishedFiles')]
    public function testThePublishedFilesPassACommonAppLint(string $file)
    {
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $number => $line) {
            $where = basename($file) . ':' . ($number + 1);

            $this->assertLessThanOrEqual(120, mb_strlen($line), $where . ' is over 120 columns');
            $this->assertStringNotContainsString("\t", $line, $where);

            $indent = strlen($line) - strlen(ltrim($line, ' '));

            // JSDoc continuation lines sit one column past the indent.
            if (str_starts_with(ltrim($line), '*')) {
                $indent--;
            }

            $this->assertSame(0, $indent % 4, $where . ' is not indented by 4 spaces');
        }
    }

    /**
     * Static `class="..."` values, `:class` bindings and the quoted strings of
     * the script (a computed may hand a class to the template).
     */
    private function classValues(string $source): string
    {
        preg_match_all('/class="([^"]*)"/', $source, $attributes);
        preg_match_all('/[\'"`][^\'"`\n]*[\'"`]/', $source, $strings);

        return ' ' . implode(' ', array_merge($attributes[1], $strings[0]));
    }
}
