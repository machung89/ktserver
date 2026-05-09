<?php

namespace App\Enums;

enum TransactionType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case SalesDelivery = 'sales_delivery';
    case SalesReturn = 'sales_return';
    case Adjustment = 'adjustment';
}
