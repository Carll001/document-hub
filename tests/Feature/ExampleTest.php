<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        $this->withoutVite();

        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('signatureEnabled', true)
                ->has('canRegister'),
            );
    }

    public function test_welcome_page_includes_disabled_signature_flag_when_feature_is_off(): void
    {
        $this->withoutVite();
        config()->set('services.document_generator.signature_enabled', false);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('signatureEnabled', false),
            );
    }
}
