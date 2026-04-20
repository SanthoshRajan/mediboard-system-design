# Key Design Decisions

## 1. Database-per-Tenant (Schema Isolation)

**Decision:**
Used database-per-tenant (`{tenant}_tenant`) instead of row-level tenancy.

**Why:**

* Strong data isolation (critical for healthcare)
* Easier backup/restore per tenant
* Simplifies query logic (no tenant_id filters everywhere)

**Trade-offs:**

* More databases to manage
* Connection pooling complexity

**Alternatives Considered:**

* Row-level tenancy → rejected due to risk of data leakage
* Schema-per-tenant → similar complexity but less isolation

---

## 2. PHP ↔ Python Bridge for PDF Generation

**Decision:**
Used direct CLI invocation instead of queue-based PDF processing.

**Why:**

* Lower latency (synchronous generation for user download)
* Simpler debugging
* No queue dependency for critical user flow

**Trade-offs:**

* Blocks request thread temporarily
* Requires Python runtime on server

**Alternatives:**

* Queue-based PDF generation → better scalability but adds delay
* Pure PHP PDF → rejected due to limited layout flexibility

---

## 3. Queue-based Tenant Provisioning

**Decision:**
Tenant creation handled asynchronously via jobs.

**Why:**

* DB creation + schema import is slow
* Prevents API blocking
* Enables retries and failure recovery

**Trade-offs:**

* Slight complexity in tracking job status
* Requires queue worker management

---

## 4. Redis for Tenant Resolution

**Decision:**
Used Redis for facility/tenant lookup.

**Why:**

* Reduces DB calls per request
* Improves latency for multi-tenant resolution

**Trade-offs:**

* Cache invalidation complexity
* Requires Redis availability

---

## 5. Monolith over Microservices

**Decision:**
Chose modular monolith architecture.

**Why:**

* Faster development
* Easier debugging
* Lower infra cost

**Trade-offs:**

* Limited independent scaling
* Larger codebase over time

**Future Path:**

* Extract PDF service or billing into microservices if needed

---

## 6. Low-resource Optimization (1GB RAM)

**Decision:**
Optimized for minimal infrastructure.

**Why:**

* Target deployment on low-cost servers
* Efficient memory usage

**Techniques:**

* Queue offloading
* Lightweight Python scripts
* Controlled concurrency

---

## 7. Tenant-aware Middleware

**Decision:**
Centralized tenant resolution in middleware.

**Why:**

* Avoid passing tenant context manually
* Reduces developer error risk
* Clean separation of concerns

---

## 8. Schema-based Initialization (SQL dump)

**Decision:**
Used pre-built SQL schema instead of migrations during provisioning.

**Why:**

* Faster tenant onboarding
* Deterministic structure

**Trade-offs:**

* Harder to version changes

**Future:**

* Hybrid approach (baseline schema + migrations)
