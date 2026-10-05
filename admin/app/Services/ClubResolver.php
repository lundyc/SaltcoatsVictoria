<?php
namespace App\Services;

class ClubResolver
{
    public function bySlug(string $slug): ?array
    {
        $stmt = db()->prepare('SELECT id, name, slug, logo_path, primary_colour, secondary_colour, accent_colour FROM clubs WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $club = $stmt->fetch();

        if (!$club) {
            return null;
        }

        $defaults = app_config('theme', []);
        $overrides = array_filter([
            'primary' => $club['primary_colour'] ?? null,
            'secondary' => $club['secondary_colour'] ?? null,
            'accent' => $club['accent_colour'] ?? null,
        ]);
        $club['theme'] = array_merge($defaults, $overrides);

        return $club;
    }
}
