# Environment Variables

---

## 🧩 Core Application

- APP_ENV
- APP_DEBUG
- APP_URL

---

## 🗄️ Database

- DB_CONNECTION
- DB_HOST
- DB_DATABASE
- DB_USERNAME
- DB_PASSWORD

---

## ⚡ Redis

- REDIS_HOST
- REDIS_DB
- REDIS_CACHE_DB
- REDIS_SESSION_DB

---

## 📁 Storage

- FILESYSTEM_DISK=tenant_storage
- TENANT_STORAGE_PATH=/storage

---

## 📄 Report Features

- REPORT_QR_CODE
- REPORT_ABNORMAL_DETECTION

---

## 📧 Communication

- MAIL_*
- WHATSAPP_*

---

## 🔐 Security

- JWT_SECRET
- BCRYPT_ROUNDS

---

## 🧠 Notes

- Python reads `.env` via `config.py`
- Feature flags control runtime behavior
- Environment differences clearly defined (local vs production)

---