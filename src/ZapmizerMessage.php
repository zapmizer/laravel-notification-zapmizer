<?php

namespace NotificationChannels\Zapmizer;

use NotificationChannels\Zapmizer\Exceptions\CouldNotSendNotification;

class ZapmizerMessage
{
    public Zapmizer $zapmizer;

    public array $params;

    public function __construct(
        public readonly string $from = '',
        public readonly string $to = '',
        array $params = ['type' => 'text']
    ) {
        $this->zapmizer = app(Zapmizer::class);

        $this->params = array_merge($params, [
            'from' => $this->from,
            'to' => $this->to,
            'interval_s' => 1,
        ]);
    }

    public static function create(string $from = '', string $to = '', array $params = ['type' => 'text']): self
    {
        return new self($from, $to, $params);
    }

    public function type(string $type): self
    {
        $this->params['type'] = $type;

        return $this;
    }

    public function text(string $text): self
    {
        $this->params['metadata']['text'] = $text;

        return $this;
    }

    /**
     * @throws CouldNotSendNotification
     */
    public function send()
    {
        $params = $this->params;

        $this->zapmizer->sendMessage($params);

        return $this;
    }
}
