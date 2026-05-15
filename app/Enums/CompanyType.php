<?php

namespace App\Enums;

enum CompanyType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';
    case Employee = 'employee';
}
