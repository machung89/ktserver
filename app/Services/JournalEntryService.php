<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class JournalEntryService
{
    /**
     * @param  array<array{account_code: string, description: string, debit: float, credit: float}>  $lines
     */
    public function create(string $description, string $entryDate, Model $reference, array $lines): JournalEntry
    {
        $this->assertBalanced($lines);

        $entry = JournalEntry::create([
            'entry_number' => $this->generateEntryNumber(),
            'entry_date' => $entryDate,
            'description' => $description,
            'is_posted' => true,
            'organization_id' => app('orgId'),
        ]);

        $entry->reference()->associate($reference);
        $entry->save();

        $this->writeLines($entry, $lines);

        return $entry->load('lines.account');
    }

    /**
     * @param  array<array{account_code: string, description: string, debit: float, credit: float}>  $lines
     */
    public function createManual(string $description, string $entryDate, array $lines): JournalEntry
    {
        $this->assertBalanced($lines);

        $entry = JournalEntry::create([
            'entry_number' => $this->generateEntryNumber(),
            'entry_date' => $entryDate,
            'description' => $description,
            'is_posted' => true,
            'organization_id' => app('orgId'),
        ]);

        $this->writeLines($entry, $lines);

        return $entry->load('lines.account');
    }

    /**
     * @param  array<array{account_code: string, description: string, debit: float, credit: float}>  $lines
     */
    public function updateLines(JournalEntry $entry, array $lines): void
    {
        $this->assertBalanced($lines);

        $entry->lines()->delete();
        $this->writeLines($entry, $lines);
    }

    public function deleteByReference(Model $reference): void
    {
        JournalEntry::where('reference_type', $reference::class)
            ->where('reference_id', $reference->id)
            ->each(function (JournalEntry $entry) {
                $entry->lines()->delete();
                $entry->delete();
            });
    }

    /**
     * @param  array<array{debit: float, credit: float}>  $lines
     *
     * @throws ValidationException
     */
    private function assertBalanced(array $lines): void
    {
        $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);

        if ($totalDebit !== $totalCredit) {
            throw ValidationException::withMessages([
                'lines' => ["Bút toán chưa cân bằng: tổng Nợ ({$totalDebit}) ≠ tổng Có ({$totalCredit})."],
            ]);
        }
    }

    /**
     * @param  array<array{account_code: string, description: string, debit: float, credit: float}>  $lines
     */
    private function writeLines(JournalEntry $entry, array $lines): void
    {
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
