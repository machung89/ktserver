<?php

namespace App\Services;

use App\Models\ActivityLog;

class ActivityLogService
{
    public function log(
        int $organizationId,
        int $causerId,
        string $action,
        string $description,
        ?int $tourId = null,
        array $properties = [],
        ?string $subjectType = null,
        ?int $subjectId = null
    ): void {
        ActivityLog::create([
            'organization_id' => $organizationId,
            'causer_id' => $causerId,
            'tour_id' => $tourId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'description' => $description,
            'properties' => $properties ?: null,
        ]);
    }
}
