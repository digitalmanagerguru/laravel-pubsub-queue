<?php

namespace Digitalmanagerguru\PubSubQueue;

use Illuminate\Support\ServiceProvider;
use Digitalmanagerguru\PubSubQueue\Connectors\PubSubConnector;

class PubSubQueueServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['queue']->addConnector('pubsub', function () {
            return new PubSubConnector;
        });
    }
}
