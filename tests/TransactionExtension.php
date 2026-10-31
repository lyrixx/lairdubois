<?php

namespace Tests;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use PHPUnit\Runner\AfterLastTestHook;
use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeFirstTestHook;
use PHPUnit\Runner\BeforeTestHook;

/**
 * Runs each test in a transaction, rolled back at its end (phpunit.xml.dist), as the PHPUnit
 * extension of dama/doctrine-test-bundle did for PHPUnit 9 until its 8.2: the one of 8.3+ only
 * speaks the events of PHPUnit 10+, and PHPUnit stays in 9.6 (tools/phpunit).
 */
final class TransactionExtension implements BeforeFirstTestHook, BeforeTestHook, AfterTestHook, AfterLastTestHook
{
    public function executeBeforeFirstTest(): void
    {
        StaticDriver::setKeepStaticConnections(true);
    }

    public function executeBeforeTest(string $test): void
    {
        StaticDriver::beginTransaction();
    }

    public function executeAfterTest(string $test, float $time): void
    {
        StaticDriver::rollBack();
    }

    public function executeAfterLastTest(): void
    {
        StaticDriver::setKeepStaticConnections(false);
    }
}
