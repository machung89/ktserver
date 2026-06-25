<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case NotIssued = 'not_issued';  // Chưa xuất
    case Proposed = 'proposed';     // Đề xuất
    case Issued = 'issued';         // Đã xuất
}
