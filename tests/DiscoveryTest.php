<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavClient;

use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavClient\Services\Discovery;
use PHPUnit\Framework\TestCase;

final class DiscoveryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        AccountData::init();
    }

    public function accountProvider(): array
    {
        return AccountData::accountProvider();
    }

    /** @dataProvider accountProvider */
    public function testAllAddressbooksCanBeDiscovered(string $accountname, array $cfg): void
    {
        $account = AccountData::$accounts[$accountname];
        $this->assertInstanceOf(Account::class, $account);

        $abookUris = [];
        foreach (AccountData::$addressbooks as $abook) {
            if ($abook->getAccount() === $account) {
                $abookUris[] = $abook->getUri();
            }
        }

        $discover = new Discovery();
        $abooks = $discover->discoverAddressbooks($account);

        $this->assertCount(count($abookUris), $abooks, "Unexpected number of addressbooks discovered");

        foreach ($abooks as $abook) {
            $this->assertContains($abook->getUri(), $abookUris, "Unexpected addressbook discovered");
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
