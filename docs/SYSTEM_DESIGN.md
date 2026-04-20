# System Design Overview

## 1. Introduction

Mediboard is a multi-tenant healthcare SaaS platform designed to manage patients, lab reports, prescriptions, and billing workflows for clinics and laboratories.

The system is built using:

- Laravel (API + orchestration layer)
- MySQL (multi-tenant data storage)
- Redis (caching + tenant resolution)
- Python (deterministic PDF generation engine)

---

## 2. High-Level Architecture

Request Flow:

Client
→ Laravel API
→ Tenant Resolution Middleware
→ Service Layer (domain logic)
→ Tenant Database

Background Processing:

API / CLI
→ Queue Dispatcher
→ Worker (CreateTenantJob)
→ DB + Storage provisioning

PDF Flow:

Laravel
→ Python CLI Script
→ Template Registry
→ PDF Generation
→ Tenant Storage

---

## 3. Multi-Tenant Strategy

### Approach: Database per Tenant

- Common DB:
  - corporate_groups
  - facilities

- Tenant DB:
  - patients, reports, invoices, inventory

### Benefits:

- Strong data isolation (no cross-tenant leakage)
- Independent backup and restore
- Horizontal scalability (DB-level distribution)

### Trade-off:

- Increased operational overhead (multiple DBs)
- Requires dynamic connection management

---

## 4. Tenant Resolution

- Tenant context is resolved via middleware (`ValidateFacility`)
- Avoids passing tenant identifiers across layers
- Redis is used for caching facility → tenant mappings

### Benefit:

- Cleaner service layer (no tenant pollution)
- Faster lookup with Redis

---

## 5. Tenant Onboarding Flow

1. CLI Command: `tenant:onboard`
2. Input validation (prevents injection / invalid names)
3. Insert into `corporate_groups`
4. Dispatch `CreateTenantJob` (async)

Job Execution:

- Create tenant database
- Execute schema (`tenant.sql`)
- Create isolated storage (`/storage/{tenant}`)
- Update provisioning status

### Failure Handling:

- DB creation failure → DROP DATABASE
- Storage failure → delete directory
- Status updated to `failed` with error message

---

## 6. PDF Generation Flow

1. Laravel triggers Python script (CLI-based execution)
2. Python:
   - Fetches report data
   - Resolves template via registry pattern
   - Generates PDF using FPDF engine
3. File stored in tenant-specific storage

### Design Choice:

- Python used for CPU-bound rendering
- CLI-based execution ensures deterministic, stateless processing

### Trade-off:

- Slight overhead vs in-process generation
- Simpler than managing async PDF queues

---

## 7. Key Design Decisions

- Database-per-tenant → prioritizes isolation over simplicity
- Queue-based provisioning → avoids blocking onboarding flow
- Redis caching → reduces DB load for tenant resolution
- Python integration → separates compute-heavy tasks from API

---

## 8. Scalability

- API layer can scale horizontally (stateless Laravel)
- Queue workers scale independently
- Tenant databases can be distributed across servers
- Storage abstraction allows migration to S3/CloudFront

---

## 9. Security

- Strong tenant isolation (DB + filesystem)
- No secrets stored in code (env-based configuration)
- Input validation for tenant provisioning
- Controlled file access (no direct public exposure)

---

## 10. Failure Scenarios

- Partial tenant provisioning → cleaned via rollback logic
- Queue job failure → retried with backoff
- Python execution failure → logged and surfaced to API
- Redis failure → fallback to DB-based resolution (optional)

---

## 11. Future Improvements

- DB sharding for high-scale tenants
- S3 + signed URLs for storage
- Kubernetes-based horizontal scaling
- Centralized logging and monitoring (ELK / OpenTelemetry)