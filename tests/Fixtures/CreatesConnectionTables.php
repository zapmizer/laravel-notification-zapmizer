<?php

namespace NotificationChannels\Zapmizer\Test\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesConnectionTables
{
    protected function defineDatabaseMigrations()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $migration = include __DIR__ . '/../../database/migrations/create_whatsapp_verifieds_table.php.stub';
        $migration->up();

        $migration = include __DIR__ . '/../../database/migrations/create_zapmizer_connections_table.php.stub';
        $migration->up();
    }

    /**
     * A team connected to Zapmizer with a known webhook secret. The Zapmizer
     * team id derives from the number: one connectable per Zapmizer team.
     */
    protected function connectedTeam(string $secret = 'whsec_test', string $number = '5581911110000', array $overrides = []): Team
    {
        $team = Team::create(['name' => 'Team ' . $number]);

        $team->zapmizerConnection()->create(array_merge([
            'api_token' => 'token-' . $number,
            'zapmizer_team_id' => (int) substr(preg_replace('/\D+/', '', $number), -6),
            'zapmizer_team_name' => 'Acme on Zapmizer',
            'phone_number' => $number,
            'bot_instance_id' => 1,
            'connected_at' => now(),
            'webhook_id' => 11,
            'webhook_secret' => $secret,
            'is_active' => true,
        ], $overrides));

        return $team;
    }
}
