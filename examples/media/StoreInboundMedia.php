<?php

namespace App\Listeners;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NotificationChannels\Zapmizer\Connect\MediaDownload;
use NotificationChannels\Zapmizer\Events\MessageReceived;
use NotificationChannels\Zapmizer\Exceptions\MediaRejectedException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Stores the media of an inbound WhatsApp message on a filesystem disk.
 *
 * Register it for NotificationChannels\Zapmizer\Events\MessageReceived. The
 * disk comes from `services.zapmizer.media_disk` (the default disk when unset).
 *
 * This listener runs inline and blocks while the bot finishes downloading
 * (up to 67 s with the default waits). In production prefer a queued job that
 * calls `media()` and releases itself on `downloading` (docs/connect.md).
 */
class StoreInboundMedia
{
    public function handle(MessageReceived $event): void
    {
        $message = $event->message;
        $connection = $event->connection;

        // No connection means a single-tenant delivery: there is no token to ask with.
        if (!$message->hasMedia || $connection === null) {
            return;
        }

        $disk = Storage::disk(config('services.zapmizer.media_disk'));
        $path = $this->pathFor($connection->getKey(), $message->id, $message->mediaMimeType());

        // Deliveries are retried: do not fetch the same message twice.
        if ($disk->exists($path)) {
            return;
        }

        try {
            // A 429 is waited out inside awaitMedia(); a 422 is not.
            $download = $connection->awaitMedia($message);
        } catch (MediaRejectedException $exception) {
            Log::warning('zapmizer media rejected', ['message' => $message->id, 'reason' => $exception->reason()]);

            return;
        }

        if ($download->isDownloading()) {
            Log::warning('zapmizer media still downloading, giving up', ['message' => $message->id]);

            return;
        }

        if ($download->isUnavailable()) {
            Log::info('zapmizer media unavailable', ['message' => $message->id]);

            return;
        }

        $this->store($disk, $path, $download);
    }

    /**
     * One fixed path per message, known before downloading, so a retried
     * delivery is a single `exists()`. Message ids are case-sensitive: only
     * the characters a path cannot carry are replaced.
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
