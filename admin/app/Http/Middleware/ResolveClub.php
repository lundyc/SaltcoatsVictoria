<?php
namespace App\Http\Middleware;

use App\Services\ClubResolver;

class ResolveClub
{
    private ClubResolver $resolver;

    public function __construct()
    {
        $this->resolver = new ClubResolver();
    }

    public function handle(): void
    {
        $slug = request_context('club_slug');
        if (empty($slug) || !preg_match('/^[a-z0-9-]+$/i', $slug)) {
            abort(404, ['message' => 'Club not found.'], 'layouts/auth');
        }

        $club = $this->resolver->bySlug($slug);
        if (!$club) {
            abort(404, ['message' => 'Club not found.'], 'layouts/auth');
        }

        request_context('club', $club);
    }
}
