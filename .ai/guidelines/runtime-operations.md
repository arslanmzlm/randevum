# Runtime & operations

- Async work (reminder/notification SMS, email, media conversion) must NEVER run synchronously.
- Separate workloads via Horizon supervisors without splitting code: `default`, `sms`, `media`, `high` (payment/appointment-critical). Conceptual runtimes: web, api (Sanctum), queue-high, queue-sms, scheduler. Keep SMS on its own queue so it can scale independently.
- Horizon and Pulse dashboards are admin-only routes.
- Scheduler: `schedule:run` every minute (cron). Backups: `backup:run` daily 03:00, `backup:clean` weekly, `backup:monitor` daily; target S3, 30-day retention.
- Health endpoint `/up`, pinged every 5 min by uptime monitoring.
- Hosting/data residency: all infra in AWS Frankfurt (eu-central-1) for KVKK adequacy — RDS PostgreSQL 16, S3 (Medialibrary + backup buckets), ElastiCache Redis (Horizon + session + cache), SES for email.
