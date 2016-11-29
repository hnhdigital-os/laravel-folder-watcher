<?php

namespace Bluora\LaravelFolderWatcher\Tests;

use Bluora\LaravelFolderWatcher\FolderWatcherCommand;
use PHPUnit\Framework\TestCase;

class FolderWatcherCommandTest extends TestCase
{
    /**
     * Assert the branch returns correctly.
     */
    public function testBranch()
    {
        $git = new GitInfo();
        $this->assertEquals($git->branch(), 'master');
    }
}
