<?php

namespace Storyfeed\Tests\Fixtures;

trait StringKeyMigrations
{
    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        config()->set('storyfeed.morph_key_type', 'string');
    }
}
