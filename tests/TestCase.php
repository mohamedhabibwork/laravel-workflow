<?php

namespace HFlow\LaravelWorkflow\Tests;

use HFlow\LaravelWorkflow\LaravelWorkflowServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'HFlow\\LaravelWorkflow\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelWorkflowServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        $migration = include __DIR__.'/../database/migrations/create_workflow_table.php.stub';
        $migration->up();

        Schema::create('test_subjects', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
