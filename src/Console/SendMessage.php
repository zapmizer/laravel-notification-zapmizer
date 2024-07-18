<?php

namespace NotificationChannels\Zapmizer\Console;

use Illuminate\Console\Command;

class SendMessage extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'zapmizer:send-message';

    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'zapmizer:send-message {message} {--from=} {--to=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send message.';

    /**
     * Execute the console command for Laravel 5.5 and newer.
     *
     * @return void
     */
    public function handle()
    {
        $this->fire();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        $this->info('Sending message...');

        $message = $this->argument('message');
        $from = $this->option('from');
        $to = $this->option('to');

        $this->info('Message: ' . $message . ' from:' . $from . ' to:' . $to);

        $params = [
            'type' => 'chat',
            'metadata' => [
                'text' => $message,
            ],
        ];

        (new \Notification\Zapmizer\ZapmizerMessage(from: config('zapmizer.from_number', $from), to: $to, params: $params))->send();

        $this->info('Message sent!');
    }
}
