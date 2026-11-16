<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use NotificationChannels\Zapmizer\Install\EnvFile;
use PHPUnit\Framework\TestCase;

class EnvFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/zapmizer-envfile-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function file(string $contents): EnvFile
    {
        file_put_contents($this->path, $contents);

        return new EnvFile($this->path);
    }

    public function testValuesAreReadTheWayDotenvReadsThem()
    {
        $file = $this->file(implode("\n", [
            'A=parli # the brand',
            'B="parli # not a comment" # but this is',
            "C='zapmizer'",
            'D=plain',
            'export E = spaced ',
            'F=',
            'G= # only a comment',
            'H=a#b',
            "I=crlf\r",
        ]) . "\n");

        $this->assertSame('parli', $file->value('A'));
        $this->assertSame('parli # not a comment', $file->value('B'));
        $this->assertSame('zapmizer', $file->value('C'));
        $this->assertSame('plain', $file->value('D'));
        $this->assertSame('spaced', $file->value('E'));
        $this->assertSame('', $file->value('F'));
        $this->assertSame('', $file->value('G'));
        $this->assertSame('a#b', $file->value('H'));
        $this->assertSame('crlf', $file->value('I'));
        $this->assertNull($file->value('MISSING'));
    }

    public function testAppendingKeepsTheFilesLineEnding()
    {
        $file = $this->file("APP_NAME=Laravel\r\nAPP_ENV=local\r\n");

        $file->addMissing(['ZAPMIZER_PARTNER_ID' => '', 'APP_ENV' => 'production']);
        $file->set('ZAPMIZER_INBOX_ENABLED', 'true');

        $this->assertSame("APP_NAME=Laravel\r\nAPP_ENV=local\r\n\r\nZAPMIZER_PARTNER_ID=\r\n\r\nZAPMIZER_INBOX_ENABLED=true\r\n", file_get_contents($this->path));
    }

    public function testSetReplacesTheValueAndKeepsTheCarriageReturn()
    {
        $file = $this->file("ZAPMIZER_INBOX_ENABLED=false\r\nNEXT=1\r\n");

        $this->assertTrue($file->set('ZAPMIZER_INBOX_ENABLED', 'true'));
        $this->assertSame("ZAPMIZER_INBOX_ENABLED=true\r\nNEXT=1\r\n", file_get_contents($this->path));
    }
}
