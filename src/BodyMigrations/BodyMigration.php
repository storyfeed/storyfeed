<?php

namespace Storyfeed\BodyMigrations;

abstract class BodyMigration
{
    abstract public function up(): void;
}
