# Failure Handling Strategy

## Tenant Creation Failures

* DB creation fails → retry
* Schema fails → rollback DB
* Storage fails → delete directories

## Job Failures

* Retries with exponential backoff
* Final failure logged in DB

## PDF Generation Failures

* Python exits with error code
* Laravel captures logs
* No partial file exposure

## Idempotency

* Existing tenant checks prevent duplication
