# System Architecture

---

## 🧩 High-Level Components

1. Laravel API Layer
2. Python Report Engine
3. MySQL (Tenant + Common DB)
4. Redis (Cache + Session)
5. File Storage (`/storage`)
6. Cron Jobs

---

## 🔄 Request Flow (Report Generation)

1. Client requests report generation via API
2. Laravel validates input and context
3. Laravel executes Python script using `exec()`
4. Python:
   - Reads `.env` via config.py
   - Fetches data from MySQL
   - Generates PDF using FPDF
   - Saves to `/storage/{tenant}/reports/`
5. Python returns file path via stdout
6. Laravel returns response to client

---

## 🧠 Why Python for Reports?

- Better control over PDF rendering
- Isolation from web request lifecycle
- Avoid PHP memory/time constraints
- Easier handling of fonts, QR, layouts

---

## 🗄️ Multi-Tenant Strategy

- Shared "common" database (metadata, global tables)
- Separate tenant data (logical separation via facility/group context)
- Storage isolation: /storage/{tenant}/reports/

---

## ⚡ Caching Strategy (Redis)

- Standard TTL: 15 days (reference data)
- Session TTL: 24 hours
- Separate DBs:
- Cache DB
- Session DB

---

## 📁 Storage Design

- Base path: `/storage`
- Organized per tenant
- Used for:
- Reports (PDF)
- QR images
- Attachments

---

## ⏱️ Background Processing

### Cron Jobs:
- Report cleanup (daily)
- Permission correction

---

## 🔐 Security Considerations

- Password hashing (Argon2id)
- Dummy hash for timing attack mitigation
- Controlled script execution via `www-data`
- File permission enforcement

---

## 📈 Scalability Considerations

- Python scripts can be moved to queue workers
- Storage can migrate to S3
- Redis already separated → ready for scaling
- Cron jobs → can move to queue/worker model

---