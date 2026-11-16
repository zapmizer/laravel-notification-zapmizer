<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Console\InstallCommand;
use PHPUnit\Framework\TestCase;

class AgentDocsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testLlmsTxtFollowsTheFormatAndLinksToFilesOfThePackage()
    {
        $llms = file_get_contents(self::ROOT . '/llms.txt');

        $this->assertMatchesRegularExpression('/\A# laravel-notification-zapmizer\n\n> \S/', $llms, 'H1 then the blockquote');
        $this->assertStringContainsString("\n## Instalação\n", $llms);

        preg_match_all('#\(https://github\.com/zapmizer/laravel-notification-zapmizer/blob/2\.x/([^)]+)\)#', $llms, $links);

        $this->assertNotEmpty($links[1]);

        foreach ($links[1] as $path) {
            $this->assertFileExists(self::ROOT . '/' . $path, "llms.txt links to {$path}");
        }
    }

    public function testTheBoostSkillHasTheFrontmatterBoostRequires()
    {
        $skill = file_get_contents(self::ROOT . '/resources/boost/skills/zapmizer-whatsapp/SKILL.md');

        $this->assertSame(1, preg_match('/\A---\n(.*?)\n---\n/s', $skill, $frontmatter));
        $this->assertMatchesRegularExpression('/^name: zapmizer-whatsapp$/m', $frontmatter[1]);
        $this->assertMatchesRegularExpression('/^description: \S.+$/m', $frontmatter[1]);
    }

    public function testTheBoostGuidelineIsPlainMarkdown()
    {
        $this->assertFileDoesNotExist(self::ROOT . '/resources/boost/guidelines/core.blade.php');

        $guideline = file_get_contents(self::ROOT . '/resources/boost/guidelines/core.md');

        $this->assertStringNotContainsString('{{', $guideline);
        $this->assertStringNotContainsString('@boostsnippet', $guideline);
        $this->assertStringContainsString('zapmizer-whatsapp', $guideline, 'it points to the skill');
    }

    public function testTheDocsPinTheSameEmbedTagAsTheInstaller()
    {
        foreach (['README.md', 'llms.txt', 'docs/instalar.md', 'resources/boost/skills/zapmizer-whatsapp/SKILL.md'] as $file) {
            preg_match_all('/zapmizer\/embed#(v[\d.]+)/', file_get_contents(self::ROOT . '/' . $file), $tags);

            $this->assertNotEmpty($tags[1], $file);

            foreach ($tags[1] as $tag) {
                $this->assertSame(InstallCommand::EMBED_VERSION, 'github:zapmizer/embed#' . $tag, $file);
            }
        }
    }

    public function testTheDocsDoNotSendTheAppToItsTsconfigForTheEmbedTypes()
    {
        // v0.1.3 ships types that resolve under `moduleResolution: node`:
        // no `paths` entry for @zapmizer/embed in the app's tsconfig.
        foreach (['README.md', 'llms.txt', 'docs/instalar.md', 'resources/boost/skills/zapmizer-whatsapp/SKILL.md'] as $file) {
            $this->assertDoesNotMatchRegularExpression('#"@zapmizer/embed/\*"\s*:#', file_get_contents(self::ROOT . '/' . $file), $file);
        }

        $this->assertStringContainsString('moduleResolution', file_get_contents(self::ROOT . '/docs/instalar.md'), 'the guide says what v0.1.3 fixes');
    }

    public function testTheDocsRequireTheBetaThatHasTheInstaller()
    {
        foreach (['README.md', 'llms.txt', 'docs/instalar.md', 'resources/boost/skills/zapmizer-whatsapp/SKILL.md'] as $file) {
            preg_match_all('/composer require "zapmizer\/laravel-notification-zapmizer:([^"]+)"/', file_get_contents(self::ROOT . '/' . $file), $constraints);

            $this->assertNotEmpty($constraints[1], $file);

            foreach ($constraints[1] as $constraint) {
                $this->assertSame('^2.0.0-beta.5@beta', $constraint, $file);
            }
        }
    }
}
