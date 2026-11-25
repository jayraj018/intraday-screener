<?php

namespace App\Services\Swing;

use App\Services\CandleStore;
use Illuminate\Support\Carbon;

/**
 * Which session a plan is for, and whether that session is over.
 *
 * There is no holiday calendar here, so the benchmark's own bars decide what was a
 * session. Until it has printed one, the next weekday is only a guess and is labelled so.
 */
class PlanValidityService
{
    public const ACTIVE = 'ACTIVE';
    public const EXPIRED = 'EXPIRED';

    /** The expected session has passed without a bar: a holiday, or prices not refreshed. */
    public const UNCONFIRMED = 'UNCONFIRMED';

    public function __construct(protected CandleStore $candles)
    {
    }

    /**
     * @param  array<int, string>  $sessions  benchmark dates, any range reaching past the signal
     * @return array{status: string, session: string, confirmed: bool, label: string}
     */
    public function forSignal(string $signalDate, array $sessions): array
    {
        $later = array_filter($sessions, fn ($date) => $date > $signalDate);
        $confirmed = $later !== [];
        $session = $confirmed ? min($later) : $this->nextWeekday($signalDate);

        $closed = now('Asia/Kolkata')->gt(
            Carbon::parse($session, 'Asia/Kolkata')->setTimeFromTimeString(config('screener.market_close'))
        );

        $status = match (true) {
            ! $closed => self::ACTIVE,
            $confirmed => self::EXPIRED,
            default => self::UNCONFIRMED,
        };

        $day = Carbon::parse($session)->format('D, d M Y');

        return [
            'status' => $status,
            'session' => $session,
            'confirmed' => $confirmed,
            'label' => match ($status) {
                self::EXPIRED => "Expired — was for {$day} only",
                self::UNCONFIRMED => "Check before using — was for {$day}, or the next session if that was a holiday",
                default => "Valid {$day} only" . ($confirmed ? '' : ' (next session if a holiday)'),
            },
        ];
    }

    /** The benchmark sessions stored since a signal. @return array<int, string> */
    public function sessionsSince(string $signalDate): array
    {
        return array_column($this->candles->get(config('swing.regime.benchmark'), '1d', from: $signalDate), 'date');
    }

    protected function nextWeekday(string $date): string
    {
        return Carbon::parse($date)->addWeekday()->toDateString();
    }
}
