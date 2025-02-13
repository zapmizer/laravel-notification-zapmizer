<?php

namespace NotificationChannels\Zapmizer;

use NotificationChannels\Zapmizer\Exceptions\CouldNotSendNotification;

class ZapmizerMessage
{
    public Zapmizer $zapmizer;

    public array $params;

    public ?string $file = null;

    public function __construct(
        public readonly string $from = '',
        public readonly string $to = '',
        array $params = ['type' => 'chat']
    ) {
        $this->zapmizer = app(Zapmizer::class);

        $this->params = array_merge($params, [
            'from' => $this->from,
            'to' => $this->to,
            'interval_s' => 1,
        ]);
    }

    public static function create(string $from = '', string $to = '', array $params = ['type' => 'chat']): self
    {
        return new self($from, $to, $params);
    }

    public function type(string $type): self
    {
        $this->params['type'] = $type;

        return $this;
    }

    public function image(string $file, string $caption): self
    {
        $this->file = $file;
        $this->params['type'] = 'image';
        $this->params['text'] = $caption;

        return $this;
    }

    public function document(string $file, string $caption): self
    {
        $this->file = $file;
        $this->params['type'] = 'document';
        $this->params['text'] = $caption;

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
        if (is_null($this->file)) {
            $this->zapmizer->sendMessage($this->params);
        } else {
            $this->zapmizer->sendMessageWithFile($this->params, $this->file);
        }

        return $this;
    }
}
