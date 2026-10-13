<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_terms_and_privacy_are_public(): void
    {
        $this->get('/welcome')->assertOk()->assertSee('Content Posting API')->assertSee(route('privacy'));
        $this->get('/terms')->assertOk()->assertSee('Terms of Service');
        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy')->assertSee('TikTok');
        $this->get('/login')->assertOk()->assertSee(route('terms'))->assertSee(route('privacy'));
    }

    public function test_site_root_shows_the_landing_page_to_visitors_and_the_studio_to_users(): void
    {
        $this->get('/')->assertRedirect(route('welcome'));
        $this->get('/autopilot')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get('/')->assertOk();
    }

    public function test_contact_email_appears_when_configured(): void
    {
        config(['services.clipper.contact_email' => 'help@example.com']);

        $this->get('/privacy')->assertSee('mailto:help@example.com', false);
    }
}
