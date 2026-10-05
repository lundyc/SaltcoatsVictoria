Tests
=====

Run the current regression checks from the project root:

```sh
php tests/run.php
```

The harness is intentionally dependency-free so it can run on the live Plesk
environment without installing PHPUnit or changing Composer setup. Tests should
prefer pure service/domain functions first. Database-backed tests can be added
later behind an explicit test database configuration.
