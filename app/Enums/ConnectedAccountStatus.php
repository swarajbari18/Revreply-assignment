<?php

declare(strict_types=1);

namespace App\Enums;

enum ConnectedAccountStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
}
