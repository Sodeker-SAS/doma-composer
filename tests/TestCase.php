<?php

declare(strict_types=1);

namespace Sodeker\Attachments\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sodeker\Attachments\AttachmentsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  mixed  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AttachmentsServiceProvider::class];
    }
}
