<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Aucun appel réel à l’API O’CHANTIER pendant les tests : chaque test simule ses réponses (Http::fake).
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => 'http://api.test/api/v1/admin']);
        Http::preventStrayRequests();
    }
}
