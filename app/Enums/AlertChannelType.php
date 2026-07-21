<?php

namespace App\Enums;

enum AlertChannelType: string
{
    case Telegram = 'telegram';
    case Email = 'email';
    case WebPush = 'webpush';
}
