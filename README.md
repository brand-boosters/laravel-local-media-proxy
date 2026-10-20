# brandboosters/laravel-local-media-proxy

Laravel integration for the **Local Media Proxy** local proxy: your app reads production (Laravel Vapor) assets
through the proxy while every write, copy and delete stays in local development storage.

* Re-points existing `s3` disks (default `LOCAL_MEDIA_PROXY_DISKS=s3`) at the proxy in local environments only.
* Fails closed: while enabled, any `s3` disk that would reach real S3 is refused; non-loopback endpoints,
  real AWS access keys and non-local environments are rejected.
* `local-media-proxy` disk driver for explicit configuration, and `php artisan local-media-proxy:status`.

Install it from the GitHub repository (the repository's root `composer.json` points at this folder):

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/brand-boosters/local-media-proxy.git", "no-api": true }
],
```

```bash
composer require --dev brandboosters/laravel-local-media-proxy:^0.1
```

See `docs/LARAVEL.md` for setup and troubleshooting.

```dotenv
LOCAL_MEDIA_PROXY_ENABLED=true
LOCAL_MEDIA_PROXY_ENDPOINT=http://127.0.0.1:9100
LOCAL_MEDIA_PROXY_BUCKET=myshop-dev
LOCAL_MEDIA_PROXY_KEY=LMPLOCAL...
LOCAL_MEDIA_PROXY_SECRET=...
```

## Tests

`composer install`, then from the repository root run `tests/run-laravel-tests.ps1`: it starts a fake
production S3 plus the real proxy and runs PHPUnit (Storage facade, GD conversions, multipart, presigned
URLs, outage handling, Spatie Media Library). The run fails if production receives any write request.
Without the harness, only the unit tests run (integration tests are skipped).
