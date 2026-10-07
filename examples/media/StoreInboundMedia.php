<?php

namespace App\Jobs;

use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NotificationChannels\Zapmizer\Connect\MediaDownload;
use NotificationChannels\Zapmizer\Exceptions\MediaRateLimitedException;
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;
use NotificationChannels\Zapmizer\InboundMessage;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use Symfony\Component\Mime\MimeTypes;

/**
 * Stores the media of an inbound WhatsApp message on a filesystem disk.
 *
 * Dispatched by App\Listeners\QueueInboundMedia. It runs on the queue because
 * the webhook answers at once and the bot may still be downloading: the job
 * asks once (`media()`) and releases itself instead of holding a worker. The
 * disk comes from `services.zapmizer.media_disk` (the default disk when unset).
 */
class StoreInboundMedia implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ZapmizerConnection $zapmizerConnection,
        public InboundMessage $message,
    ) {
    }

    /**
     * Zapmizer keeps the media for 600 s after the message was sent.
     */
    public function retryUntil(): DateTime
    {
        return $this->message->sentAt->copy()->addSeconds(600)->toDateTime();
    }

    public function handle(): void
    {
        $disk = Storage::disk(config('services.zapmizer.media_disk'));
        $path = $this->pathFor($this->zapmizerConnection->getKey(), $this->message->id, $this->message->mediaMimeType());

        // Jobs are retried and deliveries repeated: do not fetch the same message twice.
        if ($disk->exists($path)) {
            return;
        }

        try {
            $download = $this->zapmizerConnection->media($this->message);
        } catch (MediaRateLimitedException $exception) {
            $this->release($exception->retryAfter() ?? 60);

            return;
        } catch (MediaRejectedException $exception) {
            Log::warning('zapmizer media rejected', ['message' => $this->message->id, 'reason' => $exception->reason()]);
            $this->fail($exception);

            return;
        }

        if ($download->isDownloading()) {
            $this->release(5);

            return;
        }

        if ($download->isUnavailable()) {
            Log::info('zapmizer media unavailable', ['message' => $this->message->id]);

            return;
        }

        $this->store($disk, $path, $download);
    }

    /**
     * One fixed path per message, known before downloading, so a retry is a
     * single `exists()`. Message ids are case-sensitive: only the characters
     * a path cannot carry are replaced.
     */
    protected function pathFor(int|string $connectionId, string $messageId, ?string $mime): string
    {
        $extension = $mime === null ? null : (MimeTypes::getDefault()->getExtensions($mime)[0] ?? null);

        return "zapmizer/{$connectionId}/" . preg_replace('/[^A-Za-z0-9_-]/', '_', $messageId) . '.' . ($extension ?? 'bin');
    }

    protected function store(Filesystem $disk, string $path, MediaDownload $download): void
    {
        $stream = $download->stream();

        try {
            $disk->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
