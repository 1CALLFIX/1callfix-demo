<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * RefreshDatabase was commented out in Laravel's stock skeleton because
     * `/` used to render the static welcome view and touched no database at
     * all. Phase B replaced that route with the real customer homepage,
     * which reads service categories, services and FAQs — so this test now
     * needs the schema that every other feature test in this suite already
     * gets. The assertion itself is unchanged: the application's front page
     * must return 200.
     */
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
