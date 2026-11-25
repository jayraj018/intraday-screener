<?php

namespace App\Services\Swing;

use App\Models\SwingPaperTrade;
use App\Services\TelegramService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tells the owner what the paper account did and has to do next, and when a scan failed.
 *
 * What has been said is remembered per trade, so a message that could not be delivered is
 * sent by the next scan instead of being lost.
 */
class SwingAlertService
{
    protected const EXIT_REASONS = [
        'target1' => 'target hit',
        'target2' => 'target hit',
        'target_gap' => 'opened above target',
        'stop' => 'stop hit',
        'stop_gap' => 'opened below stop',
        'trailing_stop' => 'trailing stop hit',
        'max_holding_days' => 'time limit',
        'trend_invalidated' => 'trend broke',
    ];

    protected const NOT_ENTERED_REASONS = [
        'not_triggered' => 'price did not reach the entry',
        'gapped_too_far' => 'opened above the maximum price',
        'opened_past_stop' => 'opened past the stop',
        'too_large_to_size' => 'one share risks too much',
    ];

    /** A scan that skips more than this share of stocks is worth a warning. */
    protected const MAX_STALE = 0.10;

    public function __construct(
        protected TelegramService $telegram,
        protected PlanValidityService $validity,
        protected PortfolioLimitService $limits,
    ) {
    }

    /** @param  array  $result  from SwingScannerService::scan() */
    public function scanFinished(array $result): bool
    {
        $trades = SwingPaperTrade::where('portfolio', 'TAKEN')->orderBy('symbol')->get();
        $news = $trades->filter(fn ($t) => $t->alerted_status !== $t->status);

        // With nobody to tell there is nothing to catch up on later
        $sent = $this->telegram->send($this->digest($result, $trades, $news));

        if ($sent || ! $this->telegram->isConfigured()) {
            SwingPaperTrade::whereKey($news->modelKeys())->update(['alerted_status' => DB::raw('status')]);
        }

        return $sent;
    }

    public function scanFailed(string $reason): bool
    {
        return $this->telegram->send(implode("\n", [
            '🚨 Swing scan FAILED — ' . now('Asia/Kolkata')->format('d M Y, H:i'),
            $reason,
            '',
            'No new plans were saved and no exits were checked. Run the scan again after 4 PM.',
        ]));
    }

    protected function digest(array $result, Collection $trades, Collection $news): string
    {
        $lines = [
            '📊 Swing scan — ' . now('Asia/Kolkata')->format('D, d M Y'),
            sprintf('%d stocks scanned, %d READY plans', $result['scanned'], $result['states'][SwingSignal::READY] ?? 0),
        ];

        foreach ($this->warnings($result) as $warning) {
            $lines[] = "⚠️ {$warning}";
        }

        $sections = [
            $news->where('status', SwingPaperTrade::CLOSED)->map(fn ($t) => $this->exit($t)),
            $trades->filter(fn ($t) => $this->isLastSession($t))->map(fn ($t) => "⏰ LAST DAY {$t->symbol} — sell at the next session's close ({$t->rules['swing.exits']['max_holding_days']}-day limit)"),
            $news->where('status', SwingPaperTrade::OPEN)->map(fn ($t) => "✅ ENTERED {$t->symbol} at {$this->money($t->entry_price)} — stop {$this->money($t->stop_price * $t->price_factor)}, target {$this->money($t->target1 * $t->price_factor)}"),
            $news->where('status', SwingPaperTrade::NO_ENTRY)->map(fn ($t) => "➖ NOT ENTERED {$t->symbol} — " . (self::NOT_ENTERED_REASONS[$t->exit_reason] ?? $t->exit_reason)),
            $news->where('status', SwingPaperTrade::PENDING)->map(fn ($t) => $this->order($t)),
        ];

        foreach ($sections as $section) {
            if ($section->isNotEmpty()) {
                array_push($lines, '', ...$section->values());
            }
        }

        if ($news->isEmpty()) {
            array_push($lines, '', 'Nothing changed in the paper account.');
        }

        $open = $trades->where('status', SwingPaperTrade::OPEN)->count();

        return implode("\n", [...$lines, '', "Holding {$open} now. Paper account only — no money was traded."]);
    }

    /** @return array<int, string> */
    protected function warnings(array $result): array
    {
        $warnings = [];
        $paper = $result['paper'] ?? [];

        if ($result['stale'] > ($result['scanned'] + $result['stale']) * self::MAX_STALE) {
            $warnings[] = "{$result['stale']} stocks skipped: prices could not be refreshed";
        }

        if (isset($paper['error'])) {
            $warnings[] = 'The paper log could not be written. Check the logs.';
        }

        if (($paper['signals']['late'] ?? 0) > 0) {
            $warnings[] = "{$paper['signals']['late']} plans logged after the market opened and not counted. Scan after 4 PM.";
        }

        return $warnings;
    }

    protected function exit(SwingPaperTrade $trade): string
    {
        return sprintf(
            '%s EXIT %s — %s at %s on %s, %+.2fR (%s%s after charges)',
            $trade->r_multiple > 0 ? '🟢' : '🔴', $trade->symbol, self::EXIT_REASONS[$trade->exit_reason] ?? $trade->exit_reason,
            $this->money($trade->exit_price), $trade->exit_date->format('d M'), $trade->r_multiple,
            $trade->net_pnl < 0 ? '−' : '+', $this->money(abs($trade->net_pnl)),
        );
    }

    protected function order(SwingPaperTrade $trade): string
    {
        $date = $trade->signal_date->toDateString();
        $size = $this->limits->size($trade->entry_trigger ?? $trade->signal_price, $trade->stop_price);

        return sprintf(
            "🆕 PLAN %s x%d — buy %s, not above %s, stop %s, target %s\n   %s (%s)",
            $trade->symbol, $size['quantity'],
            $trade->entry_trigger ? 'above ' . $this->money($trade->entry_trigger) : 'at the open',
            $this->money($trade->max_entry), $this->money($trade->stop_price), $this->money($trade->target1),
            $this->validity->forSignal($date, $this->validity->sessionsSince($date))['label'], $trade->strategy,
        );
    }

    /** One session left before the holding limit, so there is still time to act on it. */
    protected function isLastSession(SwingPaperTrade $trade): bool
    {
        return $trade->status === SwingPaperTrade::OPEN
            && $trade->bars_held === ($trade->rules['swing.exits']['max_holding_days'] ?? 0) - 1;
    }

    protected function money(float $value): string
    {
        return '₹' . number_format($value, 2);
    }
}
