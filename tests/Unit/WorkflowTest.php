<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowTest extends TestCase
{
    public function testTheTestsRunOnPushToEveryReleaseLine()
    {
        $workflow = (string) file_get_contents(__DIR__ . '/../../.github/workflows/tests.yml');

        $this->assertSame(1, preg_match('/^  push:\n    branches: \[([^\]]*)\]$/m', $workflow, $branches));

        $list = array_map('trim', explode(',', $branches[1]));

        $this->assertContains('main', $list);
        $this->assertContains('2.x', $list, 'the 2.0 line is released from 2.x');
    }
}
