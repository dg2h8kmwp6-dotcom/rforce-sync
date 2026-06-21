# Starter Query Catalog

> **Status: starter queries.** Field names and custom (`*__c`) API names in
> this catalog are best-guesses from the integration docs and the initial
> business conversation. Some of these may not exist in the target Salesforce
> org, may carry different API names, or may be hidden by permissions on the
> MuleSoft proxy. Run `php artisan rforce:validate-queries` after pointing
> a fresh environment at the gateway and adjust the SOQL in the
> `query_definitions` table (or `QueryDefinitionSeeder`) for any rejected
> fields.

These queries are seeded by `Database\Seeders\QueryDefinitionSeeder` and
land as rows in `query_definitions`. The seeder is idempotent — running it
again updates SOQL/descriptions on matching `name` rows and adds anything
new without disturbing operator overrides for unmentioned definitions.

## Validation workflow

```bash
# Apply the catalog.
php artisan db:seed --class=Database\\Seeders\\QueryDefinitionSeeder

# Dry-run every enabled query with LIMIT 10.
php artisan rforce:validate-queries

# Target a single query with a different cap.
php artisan rforce:validate-queries --only=orders.incremental --limit=25
```

`rforce:validate-queries` does not write `raw_rforce_records`, never calls
a transformer, and continues past per-query failures. Use the report to
identify field/object mismatches without polluting sync_runs.

---

## Catalog

### `accounts.smoke`

| field          | value |
|----------------|-------|
| target dataset | `accounts` |
| business purpose | Smoke probe — confirms gateway reachability and credentials. |
| use case       | First-mile validation; used by `rforce:test-connection`. |
| schedule       | manual only |

```sql
SELECT Name FROM Account LIMIT 1
```

Notes: deliberately the smallest possible query. If this fails, every other
query in the catalog will fail for the same reason — start triage here.

---

### `orders.incremental`

| field          | value |
|----------------|-------|
| target dataset | `orders` |
| business purpose | Active / recently-modified Orders. |
| use case       | Job & order information; operational dashboards; ETA tracking. |
| schedule       | every 15 min |

```sql
SELECT Id, Name, AccountId, EffectiveDate, Status,
       CreatedDate, LastModifiedDate,
       NumberProducts__c, Number_of_Units__c,
       Estimated_Install_Duration__c,
       Adjusted_Arrival_Date__c,
       Balance_Due__c, Amount_Due__c, Amount_Paid__c
FROM Order
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
```

Field caveats:
- `NumberProducts__c`, `Number_of_Units__c`, `Estimated_Install_Duration__c`,
  `Adjusted_Arrival_Date__c`, `Balance_Due__c`, `Amount_Due__c`,
  `Amount_Paid__c` are custom fields — confirm API names in the org's
  Salesforce setup, especially capitalization and `_c` vs `__c`.

---

### `accounts.incremental`

| field          | value |
|----------------|-------|
| target dataset | `accounts` |
| business purpose | Customer master data — recently changed Accounts. |
| use case       | Customer info; dashboards; downstream joining to Orders/Contacts. |
| schedule       | every 15 min |

```sql
SELECT Id, Name, Phone,
       ShippingAddress, BillingAddress,
       Store_Number__c, Active__c, Historical__c,
       Primary_Contact__c, Sales_Rep_User__c,
       LastModifiedDate
FROM Account
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
```

Field caveats:
- `Store_Number__c`, `Active__c`, `Historical__c`, `Primary_Contact__c`,
  `Sales_Rep_User__c` are custom. The "Primary Contact" lookup may be
  exposed differently (e.g., `Primary_Contact__r.Id`).
- Compound address fields (`ShippingAddress`, `BillingAddress`) come back
  as nested JSON; the raw upsert preserves them as-is.

---

### `contacts.incremental`

| field          | value |
|----------------|-------|
| target dataset | `contacts` |
| business purpose | Contact records associated with Accounts. |
| use case       | Customer info; routing/contact preferences. |
| schedule       | every 15 min |

```sql
SELECT Id, AccountId, Name, FirstName, LastName,
       Email, Phone, MobilePhone, HomePhone,
       MailingAddress,
       Preferred_Method_of_Contact__c,
       Primary_Contact__c,
       LastModifiedDate
FROM Contact
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
```

Field caveats:
- `Preferred_Method_of_Contact__c`, `Primary_Contact__c` are custom.
- `HomePhone` is a standard field but is not enabled in every org.

---

### `order_products.incremental`

| field          | value |
|----------------|-------|
| target dataset | `order_products` |
| business purpose | Order line items / window-product specs. |
| use case       | Material list builder; install duration estimates. |
| schedule       | every 15 min |

```sql
SELECT Id, OrderId, Product2Id, Quantity, UnitPrice, TotalPrice,
       Product_Type__c, Exterior_Color__c, Interior_Color__c,
       Width__c, Height__c, Location__c,
       LastModifiedDate
FROM OrderItem
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
```

Field caveats:
- All `*__c` fields are custom; color/dimension fields may live on
  `Product2` instead of `OrderItem` depending on org modeling.
- `Width__c` / `Height__c` may be numeric or text — let the raw payload
  land and decide on type coercion in a transformer.

---

### `products.incremental`

| field          | value |
|----------------|-------|
| target dataset | `products` |
| business purpose | Product catalog (master data). |
| use case       | Material list lookups; pricing reference. |
| schedule       | hourly |

```sql
SELECT Id, Name, ProductCode, Description, Family,
       Product_Type__c, Category__c, Sub_Category__c,
       IsActive, Unit_of_Measure__c, Width__c, Height__c,
       LastModifiedDate
FROM Product2
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
```

Field caveats:
- Custom taxonomy (`Category__c`, `Sub_Category__c`, `Product_Type__c`)
  may already be flattened into `Family` in some orgs.
- `Width__c` / `Height__c` only make sense on physical SKUs — they'll be
  null on virtual products and that's fine.

---

### `sales_appointments.incremental`

| field          | value |
|----------------|-------|
| target dataset | `sales_appointments` |
| business purpose | In-home sales appointments. |
| use case       | Scheduling map / routing; crew assignment; operational dashboards. |
| schedule       | every 15 min |

```sql
SELECT Id, Name,
       Account__c, Sales_Order__c,
       Appointment_Date_Time__c, Appointment_Day__c, Appointment_Time__c,
       Assigned__c, Cancelled__c,
       Sales_Territory__c, Store__c,
       LastModifiedDate
FROM Sales_Appointment__c
WHERE LastModifiedDate > {{last_sync_at}}
ORDER BY LastModifiedDate ASC
```

Field caveats:
- `Sales_Appointment__c` is a custom object — confirm the object exists
  and isn't named e.g. `Appointment__c` instead.
- `Appointment_Day__c` and `Appointment_Time__c` may be text representations
  of the same value as `Appointment_Date_Time__c`. Decide downstream which
  is canonical.
- `Assigned__c` / `Cancelled__c` boolean conventions vary — verify
  truthy values match expectations before building dashboards.

---

## Adjusting the catalog

After validation surfaces a field/object mismatch:

1. Edit `database/seeders/QueryDefinitionSeeder.php` to fix the SOQL.
2. `php artisan db:seed --class=Database\\Seeders\\QueryDefinitionSeeder`
   (idempotent — only the offending query updates).
3. Re-run `php artisan rforce:validate-queries --only=<name>`.

If the field exists but is permission-blocked at the proxy, that's a
ticket with whoever owns the MuleSoft proxy, not a code change. The
validation command's error output prints the gateway's rejection message
verbatim so you can hand it over directly.
