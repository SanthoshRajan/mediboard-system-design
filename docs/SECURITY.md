# Security Guidelines

This document outlines security practices and threat mitigation strategies used in Mediboard.

---

## 🔐 Secrets Management

* No secrets stored in repository
* `.env` excluded via `.gitignore`
* Sensitive values:

  * DB credentials
  * Redis passwords
  * JWT secrets
  * CloudFront private keys

**Best Practice:**

* Use environment variables
* Future: AWS Secrets Manager / Vault

---

## 🧱 Multi-Tenant Isolation

* Database per tenant (`{tenant}_tenant`)
* Storage isolation (`/storage/{tenant}`)

**Security Benefit:**

* Eliminates cross-tenant data leakage
* Simplifies access control

---

## 🛡️ Input Validation & Injection Protection

* Strict validation on:

  * Tenant names
  * Facility names
* Only alphanumeric allowed

**Prevents:**

* SQL Injection (dynamic DB names)
* Path traversal (`../../etc/passwd`)

---

## 🔄 Background Job Safety

* Retry strategy: 3 attempts with backoff
* On failure:

  * DB rollback
  * Storage cleanup
  * Status marked as `failed`

**Prevents:**

* Partial tenant creation
* Inconsistent system state

---

## 📦 Storage Security

* Tenant files stored in isolated directories
* No direct public access

**Recommended:**

* Serve via signed URLs (CloudFront/S3)
* Restrict direct filesystem access

---

## 🔑 Redis Security

* Used for caching and configuration only
* No sensitive PII stored

**Hardening:**

* Password protection (optional)
* Limited exposure (internal network)

---

## 📄 PDF Generation Security

* No dynamic code execution
* Templates registered via registry pattern
* Input sanitized before rendering

**Prevents:**

* Template injection
* Code execution vulnerabilities

---

## 🚫 Sensitive Data Protection

* No exposure of:

  * Credentials
  * Private keys
  * Production configs

---

## ⚠️ Threat Model Considerations

### 1. Cross-Tenant Data Leakage

**Mitigation:**

* DB isolation
* No shared tables for sensitive data

---

### 2. Unauthorized Access

**Mitigation:**

* Middleware validation
* Token-based authentication

---

### 3. File Access Exploits

**Mitigation:**

* Controlled storage paths
* No direct URL exposure

---

### 4. Command Injection (Python bridge)

**Mitigation:**

* Strict argument validation
* No shell execution with user input

---

## ⚠️ Known Trade-offs

* Schema files included for demonstration
* In production:

  * Restrict schema access
  * Use migrations

---

## ✅ Best Practices Followed

* Principle of Least Privilege
* Fail-safe defaults
* Input sanitization
* Isolation by design

---

## 🚀 Future Improvements

* RBAC (Role-Based Access Control)
* Audit logging (who did what)
* Rate limiting APIs
* WAF (Web Application Firewall)
* Secrets rotation
