# Hub

This folder contains the consolidated club administration and social publishing application.

## Layout
- `index.php` - hub landing page and database-backed auth shell
- `config.php`, `db.php`, `auth.php`, `auth_endpoint.php`, `logout.php` - shared hub infrastructure
- `lib/` - shared player, sponsor, fixture, publishing, and media services
- `assets/` - central stylesheets, JavaScript, fonts, and application images
- `data/`, `uploads/`, `cache/`, `export/`, `logs/` - application data and generated media

## Notes
- Publishing renderer dependencies are installed from the root `package-lock.json` with `npm ci`.
- The Hub shell creates and maintains the shared authentication tables through `db.php`.
