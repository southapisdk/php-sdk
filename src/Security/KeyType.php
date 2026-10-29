<?php

declare(strict_types=1);

namespace SouthApi\Security;

enum KeyType
{
    case Payment;
    case Payout;
    case None;
}
