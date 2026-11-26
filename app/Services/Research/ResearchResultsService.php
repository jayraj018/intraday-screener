<?php

namespace App\Services\Research;

/**
 * Read-only access to the research outputs (CSV/JSON written by research/src/*.py).
 * The app never recomputes or edits these numbers; it only displays them.
 */
class ResearchResultsService
{
    public const PERIODS = ['Full 2Y', 'Recent 1Y', 'Development', 'Validation', 'Test'];

    protected function path(string $file): string
    {
        return rtrim(config('research.path'), '/') . '/' . $file;
    }

    public function available(): bool
    {
        return is_file($this->path('strategy_comparison.csv'));
    }

    /** @return array<int, array<string, string>> */
    public function csv(string $file): array
    {
        $path = $this->path($file);

        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'r');
        $head = fgetcsv($handle, escape: '\\');
        $rows = [];

        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            if (count($line) === count($head)) {
                $rows[] = array_combine($head, $line);
            }
        }

        fclose($handle);

        return $rows;
    }

    public function summary(): array
    {
        $path = $this->path('output/summary_round1.json');

        return is_file($path) ? json_decode(file_get_contents($path), true) : [];
    }

    public function comparison(string $period, string $variant = 'base'): array
    {
        return array_values(array_filter($this->csv('strategy_comparison.csv'),
            fn ($r) => $r['period'] === $period && $r['variant'] === $variant));
    }

    public function ranking(): array
    {
        return $this->csv('output/ranking.csv');
    }

    public function portfolio(string $window): array
    {
        return array_values(array_filter($this->csv('output/portfolio_results.csv'), fn ($r) => $r['window'] === $window));
    }

    /** Backtest evidence for one strategy across every period, for a signal's evidence panel. */
    public function evidence(string $strategy): array
    {
        $rows = array_filter($this->csv('strategy_comparison.csv'), fn ($r) => $r['strategy'] === $strategy && $r['variant'] === 'base');

        return collect($rows)->keyBy('period')->all();
    }

    /** @return array<int, string> chart file names that exist */
    public function charts(): array
    {
        $files = glob($this->path('charts/*.png')) ?: [];

        return array_map('basename', $files);
    }

    public function chartPath(string $name): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_\-]+\.png$/', $name)) {
            return null;
        }

        $path = $this->path('charts/' . $name);

        return is_file($path) ? $path : null;
    }

    public function reportExists(): bool
    {
        return is_file($this->path('FINAL_SWING_TRADING_RESEARCH.md'));
    }
}
