# Database Design

---

## 🧱 Database Structure

### 1. Common Schema
Contains shared/global data:
- countries, cities, provinces
- facilities
- corporate_groups
- communication logs

---

### 2. Tenant Schema
Contains operational data:
- patients, doctors
- consultations, appointments
- lab reports, prescriptions
- inventory, billing

---

## 🧠 Design Decisions

### ✅ JSON Usage

Used for:
- Clinical notes
- Lab results
- Prescriptions
- Communication payloads

**Reason:**
- Flexible schema
- Avoid frequent migrations
- Supports evolving medical formats

---

### ✅ Indexing Strategy

- High-read fields indexed:
  - patient_id, doctor_id, facility_id
- Time-based queries optimized:
  - created_at, session_date

---

### ✅ Soft Delete Strategy

- `is_active` used instead of DELETE
- Enables:
  - Audit
  - Recovery
  - Historical tracking

---

## 🔗 Relationships

- consultations → patients, doctors
- lab_reports → consultations
- invoices → consultations
- prescriptions → consultations

---

## ⚠️ Trade-offs

| Decision | Trade-off |
|--------|----------|
| JSON fields | Harder querying |
| No strict FK everywhere | More app responsibility |
| Multi-tenant logical separation | Not full DB isolation |

---