<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends plain-text messages to one Telegram chat.
 *
 * Never throws: a message that cannot be delivered must not undo the work it describes.
 */
class TelegramService
{
    /** Telegram refuses anything past 4096 characters. */
    protected const MAX_LENGTH = 4000;

    public function isConfigured(): bool
    {
        return filled(config('services.telegram.token')) && filled(config('services.telegram.chat_id'));
    }

    /** True only once every part was accepted. */
    public function send(string $text): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        foreach ($this->parts($text) as $part) {
            if (! $this->deliver($part)) {
                return false;
            }
        }

        return true;
    }

    protected function deliver(string $text): bool
    {
        try {
            $response = Http::timeout(10)->asJson()->post(
                'https://api.telegram.org/bot' . config('services.telegram.token') . '/sendMessage',
                ['chat_id' => config('services.telegram.chat_id'), 'text' => $text, 'disable_web_page_preview' => true],
            );
        } catch (\Throwable $e) {
            // The message is left out on purpose: it quotes the URL, which holds the token
            Log::warning('Telegram could not be reached: ' . class_basename($e));

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Telegram refused a message: HTTP ' . $response->status() . ' ' . $response->json('description'));
        }

        return $response->successful();
    }

    /** Split between lines, so no order is cut in half. @return array<int, string> */
    protected function parts(string $text): array
    {
        $parts = [''];

        foreach (explode("\n", $text) as $line) {
            $last = array_key_last($parts);

            if (mb_strlen($parts[$last]) + mb_strlen($line) + 1 > self::MAX_LENGTH) {
                $parts[] = $line;
            } else {
                $parts[$last] .= ($parts[$last] === '' ? '' : "\n") . $line;
            }
        }

        return $parts;
    }
}
