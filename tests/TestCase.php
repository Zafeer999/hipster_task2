<?php

namespace Zafeer\Discounts\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zafeer\Discounts\DiscountServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [
            DiscountServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $databasePath = __DIR__ . '/../testing.sqlite';
        if (file_exists($databasePath)) {
            @unlink($databasePath);
        }
        touch($databasePath);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
        ]);

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure SQLite uses WAL mode for better concurrency semantics
        try {
            DB::statement('PRAGMA journal_mode=WAL;');
            DB::statement('PRAGMA synchronous=NORMAL;');
            DB::statement('PRAGMA busy_timeout=5000;');
        } catch (\Throwable $e) {
            // ignore if not sqlite or fails
        }

        // Run package migrations from package realpath
        $packageMigrationsPath = realpath(__DIR__ . '/../src/../database/migrations') ?: __DIR__ . '/../src/../database/migrations';

        Artisan::call('migrate', [
            '--path' => $packageMigrationsPath,
            '--realpath' => true,
            '--force' => true,
        ]);

        Event::fake();
    }
}
