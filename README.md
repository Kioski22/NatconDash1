# NatconDash1

Certificate and evaluation management dashboard (PHP) with features for certificate template design, previews, generation (DOMPDF), evaluations, quizzes, and admin controls.

## Tech Stack
- PHP 8.x
- Composer (dependencies: PHPMailer, phpdotenv, dompdf)
- MySQL (MariaDB) backend
- Vanilla JS + Bootstrap UI

## Local Development
1. Copy `.env.example` to `.env` and adjust values.
2. Create database and import schema/data:
	- `natcondash1 (3).sql` (rename appropriately) via phpMyAdmin or CLI.
3. Install dependencies:
	- `composer install`
4. Serve via Apache (XAMPP) or `php -S localhost:8000` (ensure document root matches expected paths).

## Environment Variables
`db.php` reads the following (with defaults):
```
DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
APP_ENV, APP_DEBUG
MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS, MAIL_FROM_NAME
PDF_DEFAULT_FONT
```

## Testing
```
composer test
```
Basic PHPUnit scaffolding is included. Extend `tests/` with integration tests (e.g., certificate generation) before production hardening.

## CI/CD (AWS CodePipeline + CodeBuild + Elastic Beanstalk)
1. Source Stage: GitHub repository (this repo) or CodeCommit mirror.
2. Build Stage: CodeBuild project using `buildspec.yml`:
	- Installs Composer deps
	- Runs PHPUnit tests
	- Produces full artifact (excluding `.env`)
3. Deploy Stage: Elastic Beanstalk application (PHP platform 8.x) environment.

### Elastic Beanstalk Setup
1. Create EB application & environment (PHP 8.x platform).
2. Configure RDS (or separate managed DB) and supply creds via EB environment variables or `.ebextensions/00_env.config`.
3. Upload initial build artifact manually to validate.
4. Set health check URL to `/health.php` (created via `.ebextensions/01_healthcheck.config`).

### Environment Configuration
Edit `.ebextensions/00_env.config` and replace placeholders:
```
DB_HOST=RDS_ENDPOINT_HERE
DB_USER=dbuser
DB_PASS=supersecret
```
You can alternatively use EB console environment variables (preferred for secrets). If both are present, console-set values override.

### Pipeline Tips
- Enable build cache for Composer (already configured in `buildspec.yml`).
- Add a manual approval stage before production.
- Consider separate staging EB environment; promote application versions after validation.
- Add security scanning (e.g., `composer audit`) in build phase.

## Deployment (Manual Quick Start)
1. `composer install --no-dev --optimize-autoloader`
2. Zip project contents (exclude: `.git`, `tests`, local `.env`).
3. Upload zip via EB console.

## Security Notes
- Replace direct `die()` calls in production endpoints with structured error responses.
- Implement CSRF tokens for all state-changing forms (partially present already for admin pages).
- Sanitize all file uploads (currently MIME + size, consider extension whitelist & image re-encoding).
- Consider moving `uploads/` to S3 with signed URLs.

## Next Improvements
- Add integration tests for certificate PDF generation.
- Add logging abstraction (Monolog) instead of `error_log`.
- Introduce basic router / front controller to reduce duplicate bootstrap code.
- Harden authentication and password reset flows (rate limiting, token expiration).

## Navigation Flow (Updated)
1. Dashboard → Function Rooms (`function_rooms.php`)
2. Select Day (routes to `programs.php?day_id=...`)
3. Choose Program:
	- Main Program → `main_program_day.php?day_id=...` (rooms & speakers)
	- Sub Program → `sub_program_day.php?day_id=...` (departments & speakers)
4. Speaker Session Page (main: `speaker_form.php`, sub: `sub_speaker_form.php`)

Legacy pages `main_program.php` and `sub_program.php` were removed as redundant.


---
Generated CI/CD scaffolding added: `composer.json` (scripts), `buildspec.yml`, `.ebextensions/`, `phpunit.xml`, `tests/`.

