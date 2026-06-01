<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\JournalEntryResource;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JournalEntryController extends Controller
{
    use ScopedByOrganization;

    public function __construct(protected JournalEntryService $journalEntryService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $entries = JournalEntry::with('lines.account')
            ->when($request->from, fn ($q, $v) => $q->whereDate('entry_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('entry_date', '<=', $v))
            ->latest('entry_date')
            ->paginate(30);

        return JournalEntryResource::collection($entries);
    }

    public function store(Request $request): JournalEntryResource
    {
        $validated = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_code' => ['required', 'string', 'exists:accounts,code'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
        ]);

        $entry = $this->journalEntryService->createManual(
            description: $validated['description'],
            entryDate: $validated['entry_date'],
            lines: $validated['lines'],
        );

        return new JournalEntryResource($entry);
    }

    public function accounts(Request $request): JsonResponse
    {
        $accounts = Account::when(
            $request->search,
            fn ($q, $v) => $q->where('code', 'like', "%{$v}%")->orWhere('name', 'like', "%{$v}%")
        )
            ->orderBy('code')
            ->limit(50)
            ->get(['id', 'code', 'name', 'type']);

        return response()->json(['data' => $accounts]);
    }

    public function show(JournalEntry $journalEntry): JournalEntryResource
    {
        return new JournalEntryResource($journalEntry->load('lines.account'));
    }

    public function ledger(Request $request, string $accountCode): JsonResponse
    {
        $account = Account::where('code', $accountCode)->firstOrFail();

        $isDebitNormal = in_array($account->type->value, ['asset', 'expense']);

        $linesQuery = JournalEntryLine::with('journalEntry')
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('organization_id', $this->orgId()));

        $openingBalance = 0.0;
        if ($request->from) {
            $before = (clone $linesQuery)
                ->whereHas('journalEntry', fn ($q) => $q->whereDate('entry_date', '<', $request->from))
                ->get();
            $debitBefore = $before->sum(fn ($l) => (float) $l->debit_amount);
            $creditBefore = $before->sum(fn ($l) => (float) $l->credit_amount);
            $openingBalance = $isDebitNormal ? $debitBefore - $creditBefore : $creditBefore - $debitBefore;
        }

        $inPeriod = (clone $linesQuery)
            ->when($request->from, fn ($q, $v) => $q->whereHas('journalEntry', fn ($jq) => $jq->whereDate('entry_date', '>=', $v)))
            ->when($request->to, fn ($q, $v) => $q->whereHas('journalEntry', fn ($jq) => $jq->whereDate('entry_date', '<=', $v)))
            ->get()
            ->sortBy(fn ($l) => $l->journalEntry->entry_date);

        $runningBalance = $openingBalance;
        $lines = $inPeriod->map(function ($line) use (&$runningBalance, $isDebitNormal) {
            $debit = (float) $line->debit_amount;
            $credit = (float) $line->credit_amount;
            $runningBalance += $isDebitNormal ? ($debit - $credit) : ($credit - $debit);

            return [
                'id' => $line->id,
                'entry_date' => $line->journalEntry->entry_date,
                'entry_number' => $line->journalEntry->entry_number,
                'description' => $line->description ?: $line->journalEntry->description,
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                'balance' => $runningBalance,
            ];
        })->values();

        $periodDebit = $inPeriod->sum(fn ($l) => (float) $l->debit_amount);
        $periodCredit = $inPeriod->sum(fn ($l) => (float) $l->credit_amount);

        return response()->json([
            'account' => ['code' => $account->code, 'name' => $account->name, 'type' => $account->type->value],
            'is_debit_normal' => $isDebitNormal,
            'opening_balance' => $openingBalance,
            'period_debit' => $periodDebit,
            'period_credit' => $periodCredit,
            'closing_balance' => $runningBalance,
            'lines' => $lines,
        ]);
    }
}
