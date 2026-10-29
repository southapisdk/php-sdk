<?php

declare(strict_types=1);

namespace SouthApi;

use SouthApi\Http\GuzzleTransport;
use SouthApi\Http\Session;
use SouthApi\Http\Transport;
use SouthApi\Resources\{Balances, DepositAddresses, Payments, Rates, Settings, Transactions, Transfers, Withdrawals};
use SouthApi\Security\Credentials;
use SouthApi\Security\Redacted;

final readonly class Client
{
    use Redacted;

    public Payments $payments;
    public DepositAddresses $depositAddresses;
    public Withdrawals $withdrawals;
    public Transfers $transfers;
    public Balances $balances;
    public Transactions $transactions;
    public Rates $rates;
    public Settings $settings;

    public function __construct(
        #[\SensitiveParameter] Credentials $credentials = new Credentials(),
        Configuration $configuration = new Configuration(),
        #[\SensitiveParameter] ?Transport $transport = null,
    ) {
        $session = new Session($credentials, $configuration, $transport ?? new GuzzleTransport($configuration));
        $this->payments = new Payments($session);
        $this->depositAddresses = new DepositAddresses($session);
        $this->withdrawals = new Withdrawals($session);
        $this->transfers = new Transfers($session);
        $this->balances = new Balances($session);
        $this->transactions = new Transactions($session);
        $this->rates = new Rates($session);
        $this->settings = new Settings($session);
    }
}
