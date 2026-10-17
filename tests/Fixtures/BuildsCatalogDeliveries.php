<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Support\Str;

/**
 * Envelopes of the webhook catalog (`webhooks` in the contract), shaped as
 * Zapmizer delivers them: `{id, created_at, wid, name, data}`. The default
 * `data` of each event validates against its schema.
 */
trait BuildsCatalogDeliveries
{
    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function catalogData(): array
    {
        return [
            'message_status' => [
                'wapp_message_id' => '3EB0C767D82B6A6C7A2F',
                'to' => '5581999998888@c.us',
                'ack' => 2,
                'status' => 'delivered',
                'timestamp' => 1760659200,
            ],
            'connection' => [
                'state' => 'disconnected',
                'reason' => 'LOGOUT',
                'kind' => 'logged_out',
            ],
            'message_reacted' => [
                'wapp_message_id' => '3EB0C767D82B6A6C7A2F',
                'chat_wid' => '5581999998888@c.us',
                'from' => '5581999998888@c.us',
                'emoji' => '👍',
                'removed' => false,
                'timestamp' => 1760659200,
            ],
            'message_edited' => [
                'wapp_message_id' => '3EB0C767D82B6A6C7A2F',
                'chat_wid' => '5581999998888@c.us',
                'from_me' => false,
                'body' => 'new text',
                'previous_body' => 'old text',
                'timestamp' => 1760659200,
            ],
            'message_revoked' => [
                'wapp_message_id' => '3EB0C767D82B6A6C7A2F',
                'chat_wid' => '5581999998888@c.us',
                'from_me' => false,
                'scope' => 'everyone',
                'timestamp' => 1760659200,
            ],
            'message_transcribed' => [
                'wapp_message_id' => '3EB0C767D82B6A6C7A2F',
                'chat_wid' => '5581999998888@c.us',
                'text' => 'hello there',
                'duration_seconds' => 4.5,
                'model' => 'whisper-1',
                'source' => 'auto',
                'timestamp' => 1760659200,
            ],
            'incoming_call' => [
                'call_id' => 'CALL1234',
                'from' => '5581999998888@c.us',
                'is_video' => true,
                'is_group' => false,
                'from_me' => false,
                'timestamp' => 1760659200,
            ],
            'poll_vote' => [
                'wapp_message_id' => '3EB0C767D82B6A6C7A2F',
                'chat_wid' => '5581999998888@c.us',
                'voter' => '5581999998888@c.us',
                'selected_options' => ['Yes', 'Maybe'],
                'timestamp' => 1760659200,
            ],
            'contact_number_changed' => [
                'old_wid' => '5581999998888@c.us',
                'new_wid' => '5581977776666@c.us',
                'is_contact' => true,
                'timestamp' => 1760659200,
            ],
            'group_membership' => [
                'group_wid' => '120363000000000000@g.us',
                'kind' => 'removed',
                'by' => '5581999998888@c.us',
                'timestamp' => 1760659200,
            ],
            'group_admins_changed' => [
                'group_wid' => '120363000000000000@g.us',
                'kind' => 'promoted',
                'participants' => ['5581999998888@c.us', '5581977776666@c.us'],
                'by' => '5581955554444@c.us',
                'timestamp' => 1760659200,
            ],
            'group_join_request' => [
                'group_wid' => '120363000000000000@g.us',
                'requester' => '5581999998888@c.us',
                'participants' => ['5581999998888@c.us'],
                'timestamp' => 1760659200,
            ],
            'group_updated' => [
                'group_wid' => '120363000000000000@g.us',
                'change' => 'subject',
                'body' => 'New group name',
                'by' => '5581999998888@c.us',
                'timestamp' => 1760659200,
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $data Replaces the default data when given.
     * @param array<string, mixed> $envelope Overrides of the envelope fields.
     * @return array<string, mixed>
     */
    protected function catalogEnvelope(string $name, ?array $data = null, array $envelope = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'created_at' => '2025-10-17T12:00:00.000000Z',
            'wid' => '5581911110000@c.us',
            'name' => $name,
            'data' => $data ?? static::catalogData()[$name],
        ], $envelope);
    }

    protected static function catalogSchema(string $name): string
    {
        return "#/webhooks/{$name}/post/requestBody/content/application~1json/schema";
    }
}
