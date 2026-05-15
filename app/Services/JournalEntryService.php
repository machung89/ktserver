<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;

class JournalEntryService
{
    /**
     * @param  array<array{account_code: string, description: string, debit: float, credit: float}>  $lines
     */
    public function create(string $description, string $entryDate, Model $reference, array $lines): JournalEntry
    {
        $entry = JournalEntry::create([
            'entry_number' => $this->generateEntryNumber(),
            'entry_date' => $entryDate,
            'description' => $description,
            'is_posted' => true,
            'organization_id' => app('orgId'),
        ]);

        $entry->reference()->associate($reference);
        $entry->save();

        $accountCache = [];

        foreach ($lines as $line) {
            $code = $line['account_code'];

            if (! isset($accountCache[$code])) {
                $accountCache[$code] = Account::where('code', $code)->firstOrFail();
            }

            $entry->lines()->create([
                'account_id' => $accountCache[$code]->id,
                'description' => $line['description'] ?? null,
                'debit_amount' => $line['debit'] ?? 0,
                'credit_amount' => $line['credit'] ?? 0,
            ]);
        }

        return $entry->load('lines.account');
    }

    /**
     * @param  array<array{account_code: string, description: string, debit: float, credit: float}>  $lines
     */
    public function updateLines(JournalEntry $entry, array $lines): void
    {
        $entry->lines()->delete();

        $accountCache = [];

        foreach ($lines as $line) {
            $code = $line['account_code'];

            if (! isset($accountCache[$code])) {
                $accountCache[$code] = Account::where('code', $code)->firstOrFail();
            }

            $entry->lines()->create([
                'account_id' => $accountCache[$code]->id,
                'description' => $line['description'] ?? null,
                'debit_amount' => $line['debit'] ?? 0,
                'credit_amount' => $line['credit'] ?? 0,
            ]);
        }
    }

    private function generateEntryNumber(): string
    {
        $last = JournalEntry::orderByDesc('id')->lockForUpdate()->first();
        $seq = $last ? ((int) substr($last->entry_number, 3)) + 1 : 1;

        return 'BT-'.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
