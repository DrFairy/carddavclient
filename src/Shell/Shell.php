<?php

/**
 * Simple CardDAV Shell, mainly for debugging the library.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient\Shell;

use MStilkerich\CardDavClient\{AddressbookCollection, Config};
use MStilkerich\CardDavClient\Services\{Discovery, Sync};
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Bramus\Monolog\Formatter\ColoredLineFormatter;

class Shell
{
    private const HISTFILE = ".davshell_history";

    private const COMMANDS = [
        'help' => [
            'synopsis' => 'Lists available commands or displays help on a specific command',
            'usage'    => 'Usage: discover <accountname>',
            'help'     => "If no command is specified, prints a list of available commands,\n"
                . "otherwise prints help on the specified command.",
            'callback' => 'showHelp',
            'minargs'  => 0
        ],
        'discover' => [
            'synopsis' => 'Discovers the available addressbooks in a specified CardDAV account',
            'usage'    => 'Usage: discover <accountname>',
            'help'     => "Discovers the available addressbooks in the specified account using the mechanisms\n"
                . "described by RFC6764 (DNS SRV/TXT lookups, /.well-known URI lookup, plus default locations).",
            'callback' => 'discoverAddressbooks',
            'minargs'  => 1
        ],
        'accounts' => [
            'synopsis' => 'Lists the available accounts',
            'usage'    => 'Usage: accounts [-p]',
            'help'     => "Lists the available accounts.\n"
                . "Option -p: Include the passwords with the output",
            'callback' => 'listAccounts',
            'minargs'  => 0
        ],
        'add_account' => [
            'synopsis' => 'Adds an account',
            'usage'    => 'Usage: add_account <name> <server> <username> <password>',
            'help'     => "Adds a new account to the list of accounts."
                . "name:   An arbitrary (but unique) name that the account is referenced by within this shell.\n"
                . "server: A servername or URI used as the basis for discovering addressbooks in the account.\n"
                . "username: Username used to authenticate with the server.\n"
                . "password: Password used to authenticate with the server.\n",
            'callback' => 'addAccount',
            'minargs'  => 4
        ],
        'addressbooks' => [
            'synopsis' => 'Lists the available addressbooks',
            'usage'    => 'Usage: accounts [<accountname>]',
            'help'     => "Lists the available addressbooks for the specified account.\n"
                . "If no account is specified, lists the addressbooks for all accounts. The list includes an"
                . "identifier for each addressbooks to be used within this shell to reference this addressbook in"
                . "operations",
            'callback' => 'listAddressbooks',
            'minargs'  => 0
        ],
        'show_addressbook' => [
            'synopsis' => 'Shows detailed information on the given addressbook.',
            'usage'    => 'Usage: show_addressbook [<addressbook_id>]',
            'help'     => "addressbook_id: Identifier of the addressbook as provided by the \"addressbooks\" command.",
            'callback' => 'showAddressbook',
            'minargs'  => 1
        ],
        'synchronize' => [
            'synopsis' => 'Synchronizes an addressbook to the local cache.',
            'usage'    => 'Usage: synchronize [<addressbook_id>]',
            'help'     => "addressbook_id: Identifier of the addressbook as provided by the \"addressbooks\" command.",
            'callback' => 'syncAddressbook',
            'minargs'  => 1
        ],
    ];

    /** @var array */
    private $accounts;

    /** @var LoggerInterface */
    public static $logger;

    public function __construct(array $accountdata = [])
    {
        $this->accounts = $accountdata;


        $log = new Logger('davshell');
        $handler = new StreamHandler('php://stdout', Logger::DEBUG);
        $handler->setFormatter(new ColoredLineFormatter(
            null,
            "%message% %context% %extra%\n",
            "",   // no date output needed
            true, // allow linebreaks in message
            true  // remove empty context and extra fields (trailing [] [])
        ));
        $log->pushHandler($handler);
        self::$logger = $log;

        $httplog = new Logger('davshell');
        $httphandler = new StreamHandler('http.log', Logger::DEBUG, true, 0600);
        $httphandler->setFormatter(new LineFormatter(
            "[%datetime%] %level_name%: %message% %context% %extra%",
            'Y-m-d H:i:s', // simplified date format
            true, // allow linebreaks in message
            true  // remove empty context and extra fields (trailing [] [])
        ));
        $httplog->pushHandler($httphandler);

        Config::init($log, $httplog);
    }

    private function listAccounts(string $opt = ""): bool
    {
        $showPw = ($opt == "-p");

        foreach ($this->accounts as $name => $accountInfo) {
            if (!$showPw) {
                unset($accountInfo['password']);
            }
            self::$logger->info("Account $name", $accountInfo);
        }

        return true;
    }

    private static function commandCompletion(string $word, int $index): array
    {
        // FIXME to be done
        //Get info about the current buffer
        $rl_info = readline_info();

        // Figure out what the entire input is
        $full_input = substr($rl_info['line_buffer'], 0, $rl_info['end']);

        $matches = array();

        // Get all matches based on the entire input buffer
        //foreach (phrases_that_begin_with($full_input) as $phrase) {
            // Only add the end of the input (where this word begins)
            // to the matches array
        //    $matches[] = substr($phrase, $index);
        //}

        return $matches;
    }

    private function addAccount(string $name, string $srv, string $usr, string $pw): bool
    {
        $ret = false;

        if (key_exists($name, $this->accounts)) {
            self::$logger->error("Account named $name already exists!");
        } else {
            $this->accounts[$name] = [
                'server'   => $srv,
                'username' => $usr,
                'password' => $pw
            ];
            $ret = true;
        }

        return $ret;
    }

    private function showHelp(string $command = null): bool
    {
        $ret = false;

        if (isset($command)) {
            if (isset(self::COMMANDS[$command])) {
                self::$logger->info("$command - " . self::COMMANDS[$command]['synopsis']);
                self::$logger->info(self::COMMANDS[$command]['usage']);
                self::$logger->info(self::COMMANDS[$command]['help']);
                $ret = true;
            } else {
                self::$logger->error("Unknown command: $command");
            }
        } else {
            foreach (self::COMMANDS as $command => $commandDesc) {
                self::$logger->info("$command: " . $commandDesc['synopsis']);
            }
        }

        return $ret;
    }

    private function discoverAddressbooks(string $accountName): bool
    {
        $retval = false;

        if (isset($this->accounts[$accountName])) {
            [ 'server' => $srv, 'username' => $username, 'password' => $password ] = $this->accounts[$accountName];

            $discover = new Discovery();
            $abooks = $discover->discoverAddressbooks($srv, $username, $password);

            $this->accounts[$accountName]['addressbooks'] = [];
            foreach ($abooks as $abook) {
                self::$logger->notice("Found addressbook: $abook");
                $this->accounts[$accountName]['addressbooks'][] = $abook;
            }
            $retval = true;
        } else {
            self::$logger->error("Unknown account $accountName");
        }

        return $retval;
    }

    private function listAddressbooks(string $accountName = null): bool
    {
        $ret = false;

        if (isset($accountName)) {
            if (isset($this->accounts[$accountName])) {
                $accounts = [ $accountName => $this->accounts[$accountName] ];
                $ret = true;
            } else {
                self::$logger->error("Unknown account $accountName");
            }
        } else {
            $accounts = $this->accounts;
            $ret = true;
        }

        foreach ($this->accounts as $name => $accountInfo) {
            $id = 0;

            foreach (($accountInfo["addressbooks"] ?? []) as $abook) {
                self::$logger->info("$name@$id - $abook");
                ++$id;
            }
        }

        return $ret;
    }

    private function showAddressbook(string $abookId): bool
    {
        $ret = false;

        if (preg_match("/^(.*)@(\d+)$/", $abookId, $matches)) {
            [, $accountName, $abookIdx] = $matches;

            $abook = $this->accounts[$accountName]["addressbooks"][$abookIdx] ?? null;

            if (isset($abook)) {
                self::$logger->info($abook->getDetails());
                $ret = true;
            } else {
                self::$logger->error("Invalid addressbook ID $abookId");
            }
        } else {
            self::$logger->error("Invalid addressbook ID $abookId");
        }

        return $ret;
    }

    private function syncAddressbook(string $abookId): bool
    {
        $ret = false;

        if (preg_match("/^(.*)@(\d+)$/", $abookId, $matches)) {
            [, $accountName, $abookIdx] = $matches;

            $abook = $this->accounts[$accountName]["addressbooks"][$abookIdx] ?? null;

            if (isset($abook)) {
                $synchandler = new ShellSyncHandler();
                $syncmgr = new Sync();
                $synctoken = $syncmgr->synchronize($abook, $synchandler);
                $ret = true;
            } else {
                self::$logger->error("Invalid addressbook ID $abookId");
            }
        } else {
            self::$logger->error("Invalid addressbook ID $abookId");
        }

        return $ret;
    }

    public function run(): void
    {
        readline_read_history(self::HISTFILE);

        while ($cmd = readline("> ")) {
            $cmd = trim($cmd);
            $tokens = preg_split("/\s+/", $cmd);
            $command = array_shift($tokens);

            if (isset(self::COMMANDS[$command])) {
                if (count($tokens) >= self::COMMANDS[$command]['minargs']) {
                    if (call_user_func_array([$this, self::COMMANDS[$command]['callback']], $tokens)) {
                        readline_add_history($cmd);
                    }
                } else {
                    self::$logger->error("Too few arguments to $command.");
                    self::$logger->info(self::COMMANDS[$command]['usage']);
                }
            } else {
                self::$logger->error("Unknown command $command. Type \"help\" for a list of available commands");
            }
        }

        readline_write_history(self::HISTFILE);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
