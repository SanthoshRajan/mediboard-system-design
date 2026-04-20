# Tenant Resolution Flow

## Overview

Each request is mapped to a tenant dynamically using facility context.

## Flow

1. Request hits API
2. `ValidateFacility` middleware extracts facility_id
3. Facility data fetched from Redis
4. TenantContext built:

   * Database name
   * Storage path
5. Services use TenantContext for DB queries

## Benefits

* Strong isolation (DB per tenant)
* Fast lookup using Redis
* No cross-tenant data leakage
