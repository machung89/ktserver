<?php

namespace App\Enums;

enum TransactionType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case SalesDelivery = 'sales_delivery';
    case SalesReturn = 'sales_return';
    case Adjustment = 'adjustment';
    case RecipeConsumption = 'recipe_consumption';
    case ProductionConsumption = 'production_consumption';
    case ProductionReceipt = 'production_receipt';
}
