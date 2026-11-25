<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TestTelegram extends Command
{
    protected $signature = 'telegram:test';

    protected $description = 'Send a test message to the configured Telegram chat';

    public function handle(TelegramService $telegram): int
    {
        if (! $telegram->isConfigured()) {
            $this->error('Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID in .env first.');

            return self::FAILURE;
        }

        if (! $telegram->send('✅ Intraday Screener can send you swing alerts.')) {
            $this->error('Telegram did not accept the message. The reason is in storage/logs/laravel.log.');

            return self::FAILURE;
        }

        $this->info('Sent. Check Telegram.');

        return self::SUCCESS;
    }
}
