# Queue Architecture

## Overview

The system uses Laravel queues to handle asynchronous and long-running operations such as tenant provisioning.

## Queue Types

* `tenant-provisioning` → Tenant provisioning jobs
* `default` → General jobs

## Worker Command

```bash
php artisan queue:work --queue=tenant-provisioning --tries=3 --timeout=300
```

## Retry Strategy

* Max attempts: 3
* Backoff: 30s, 120s
* Failures logged in `failed_jobs`

## Why Queues?

* Prevents blocking API requests
* Ensures reliability with retries
* Enables horizontal scaling

## Failure Handling

* Partial setup is rolled back:

  * Database dropped
  * Storage cleaned
* Status updated in `corporate_groups`
