<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaRoutingTest extends TestCase
{
    public function test_renders_spa_shell_for_frontend_paths(): void
    {
        $response = $this->get('/items');

        $response->assertViewIs('app');
    }

    public function test_returns_json_404_for_unknown_api_path(): void
    {
        $response = $this->get('/api/v1/unknown');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
