<?php

namespace App\Enums;

enum PaymentType: string
{
    case Receipt = 'receipt';
    case Payment = 'payment';
}
