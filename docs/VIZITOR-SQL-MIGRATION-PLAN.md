# Vizitor → SQL Server migration plan (evidence-first)

> Status: **BLOCKED on source code.** No Vizitor Android source, no PHP/IIS API source and no
> `Atiran2` schema exist in this workspace. The only file in the repository is
> `meelano-trading-intelligence-v4.0-pro.zip`, which contains `api5/` — an unrelated PHP
> crypto-trading API (68 entries, zero `.kt`/`.java`/`.gradle`/`.sql` files).
>
> Per your rule #19 (*do not guess table/column names, do not fabricate queries*), **no application
> code will be written until the evidence below exists.** What follows is the plan, the
> architecture, and the tooling that produces that evidence.

---

## 0. What I need from you (pick the easy path)

| # | Artifact | Why it is mandatory | How to deliver |
|---|---|---|---|
| A | **Vizitor Android source** (`Vizitor-2.17.1-Luxury-AI-Final/`, the whole folder — `app/src`, `build.gradle*`, `AndroidManifest.xml`, `gradle/libs.versions.toml`, `proguard*`) | Requirement #1, #15, #17: every Activity/Fragment/ViewModel/Repository/DTO/endpoint must be inventoried before anything is removed | Attach the zip, or push it to branch `arena/01a0a998-trd` |
| B | **PHP/IIS API source** (the folder behind the endpoints the app calls) | Requirement #17: the Endpoint → SQL map must come from the real code, not from inference | Attach the zip |
| C | **`Atiran2` schema inventory** = output of `discovery/sql/01_inventory_atiran2.sql` | Requirement #4, #7–#11: exact column names/types, PK/FK, proc signatures, proc bodies, driver-compatibility risks | Run the script, send me the `.txt` |
| D | **SQL Server version + TLS posture + where the phone connects from** (LAN / VPN / public internet) | Decides which JDBC driver is even usable (see §2) | One line of text, or Section 0 + 7 of the inventory |

If A/B cannot leave your machine, run the two scanners locally (`discovery/tools/*.ps1`) and send
me only the generated `.md` + `.csv` files — that is enough to start, and it keeps your code private.

---

## 1. Target architecture (as you specified)

```
UI (Activity / Fragment / Compose)          <- unchanged, requirement #15
   |  StateFlow / LiveData
ViewModel                                   <- unchanged responsibility: state + validation
   |  suspend fun
Repository (interface)  ..................  <- the seam where PHP-API impl is swapped for SQL impl
   |                                          both implementations can coexist during migration
SqlXxxDataSource (suspend, Dispatchers.IO)  <- requirement #13: nothing on the main thread
   |  parameterized SQL only                  requirement #12: no string concatenation
ConnectionManager                           <- connect / validate / retry / timeout / pool / dispose
   |
JDBC driver  ->  SQL Server [SQL_SERVER_IP]:1433  ->  Atiran2
```

Layer rules that will be enforced in code review:

1. **No SQL outside `data/sql/`.** ViewModels never see `ResultSet`.
2. **Models ≠ DTOs ≠ rows.** `Row` (raw mapping) → `Domain model` (business) → `UiState`. Your
   existing DTOs stay as the domain models so the UI does not change.
3. **Every read is `suspend` + `Dispatchers.IO` + explicit `withTimeout`.**
4. **Every write goes through one `TransactionRunner`** (autoCommit=false → commit/rollback in
   `finally`) so requirement #11 is structurally guaranteed, not per-call discipline.
5. **Visitor scope is a server-side predicate, applied in one place** (`VisitorScope` value object
   injected into every customer/order/price query), so requirement #6 cannot be forgotten per screen.
6. **Errors are typed** (`DbError.Unreachable / Timeout / Auth / Permission / Sql / Schema`), never
   swallowed, never leaking credentials into logs or crash reports (requirement #12, #14).
7. **Connection config comes from `EncryptedSharedPreferences`** (Keystore-backed) + a Settings
   screen for Server / Port / Database / User / Password (requirement #3). Nothing hardcoded,
   nothing printed to logcat, password field never logged.

Planned package layout (Kotlin, to be confirmed against the real project's conventions):

```
data/
  sql/
    ConnectionManager.kt        // driver load, URL build, pool, validate, retry, timeouts
    SqlConnectionConfig.kt      // server/port/db/user/secret + secure storage
    TransactionRunner.kt        // commit/rollback/idempotency guard
    SqlErrorMapper.kt           // SQLException -> typed DbError, credential-scrubbing
    queries/                    // ONE file per bounded context, SQL as constants w/ named params
      AuthQueries.kt  CustomerQueries.kt  ProductQueries.kt  PriceQueries.kt
      StockQueries.kt OrderQueries.kt     VisitorQueries.kt
    dao/                        // RowMappers: ResultSet -> Row (explicit column list, no SELECT *)
  repository/                   // interfaces (already exist?) + *SqlRepository implementations
  remote/                       // existing Retrofit stack - kept until every screen is migrated
domain/model/                   // existing models/DTOs, untouched
ui/                             // untouched
```

---

## 2. The hard technical reality of “Android → SQL Server :1433” (decide before coding)

This is the single biggest risk in the whole request, and it is not a coding-style question:

| Driver | Verdict on Android | Notes |
|---|---|---|
| **Microsoft `mssql-jdbc`** | **Does not work.** It targets desktop/server JVMs and Android is not in Microsoft's support matrix; community attempts fail to even compile. | [1](https://www.daniweb.com/programming/mobile-development/threads/348340/connecting-android-application-to-a-local-ms-sql-server-database), [7](https://www.b4x.com/android/forum/threads/connect-to-ms-sql-server-with-ssl-may-be-using-mssql-jdbc-8-4-1-jre11-posible.125177/) |
| **jTDS 1.3.1** (the de-facto Android choice) | Works, with two serious caveats. It is a JDBC 3.0 type-4 driver aimed at SQL Server 6.5 → 2012. | [3](https://www.codeproject.com/Tips/1054377/Direct-Access-to-SQL-Server-From-Android) |
| jTDS caveat #1: **no TLS 1.2** in the official build → when the server enforces TLS 1.2 (default on current Windows Server), the login fails with *“DB server closed connection”*. | An unofficial SourceForge patch (#129) adds TLS 1.0/1.1/1.2 but is user-supplied, unmaintained, and had a hang on JRE 1.8. | [1](https://support.liquibase.com/hc/en-us/articles/42695099387931-Connection-Failure-to-SQL-Server-RDS-After-Enabling-Forced-SSL), [3](https://knowledge.broadcom.com/external/article/137242/database-job-failed-when-tls-12-is-enabl.html), [4](https://dba.stackexchange.com/questions/259413/why-does-tls1-2-break-connections-to-sql-server), [4](https://sourceforge.net/p/jtds/patches/129/) |
| jTDS caveat #2: **no `datetime2` / `date` / `time` / `datetimeoffset` / `sql_variant` support.** The TDS protocol itself is backwards compatible, so old clients still connect to newer servers — the breakage is in the *data types*. | Section 7 of `01_inventory_atiran2.sql` reports exactly which risky types appear in the tables Vizitor touches. If any are in the read path we must `CAST(... AS datetime/varchar)` in the query or wrap the column in a view. | [3](https://stackoverflow.com/questions/52876630/jtds-1-3-1-and-sql-server-2014) |

**Consequence for requirement #12 (“enable TLS if possible”):** it may *not* be possible at the TDS
layer with the only usable driver. The realistic encryption strategy is therefore:

* **Option A (preferred if phones are on a customer LAN):** keep 1433 bound to the internal subnet,
  firewall-restricted, no internet exposure; TLS at the TDS layer optional.
* **Option B (preferred if phones roam):** put the device on a **WireGuard/IPsec VPN**; SQL Server
  only listens for the VPN subnet. The tunnel provides the confidentiality that jTDS cannot.
* **Option C:** use the patched jTDS build + server certificate and accept an unmaintained
  dependency in a production ERP path (must be a conscious decision, documented).
* **Option D (what I would recommend for the *write* path):** direct SQL for reads, and keep the
  posting of proforma/invoice behind the existing PHP/Atiran procedure call path or a thin
  authenticated bridge — because an invoice posted from a decompilable client with a DB credential
  is the highest-damage failure mode in this system.

I will implement whichever you choose; I just refuse to silently pick one, because A–D have
completely different firewall, driver and code consequences.

---

## 3. Security model (given the credential lives on the phone)

Stating it once, plainly: **anything shipped inside an APK can be extracted** (jadx/apktool on a
rooted device). Obfuscation and Keystore storage raise the cost, they do not make the secret safe.
Therefore the real control must be **server-side**:

1. Dedicated login `vizitor_app` — never `sa`, never a domain admin, never `db_owner`.
   Template: `discovery/sql/02_least_privilege_login.sql`.
2. `SELECT` only on the exact objects Vizitor reads; `EXECUTE` only on the exact posting procs;
   `INSERT/UPDATE` only where the app truly writes. Explicit `DENY` on `xp_cmdshell`, `sp_OACreate`,
   `VIEW SERVER STATE`, `ALTER ANY LOGIN`.
3. `CHECK_POLICY = ON`; rotate on a schedule; logins audited.
4. SQL Server firewall rule restricted to the LAN/VPN subnet — never `0.0.0.0/0`.
5. On-device: `EncryptedSharedPreferences` (Android Keystore) for server/db/user/password,
   password field `inputType=textPassword`, never written to logcat, never in crash reports
   (scrub in the `UncaughtExceptionHandler` and in `SqlErrorMapper`).
6. Parameterized statements **only** (`PreparedStatement`), no `SELECT *`, explicit column lists,
   `SET ROWCOUNT`/`OFFSET-FETCH` pagination, statement + login timeouts, `try/finally` close
   (no connection leaks), small bounded pool.

---

## 4. Migration phases

| Phase | Work | Exit criteria (evidence, not opinion) |
|---|---|---|
| **0 – Inventory** | Run `01_inventory_atiran2.sql`, `Scan-AndroidProject.ps1`, `Scan-PhpApi.ps1` | Three inventories delivered; every screen mapped to endpoint(s); every endpoint mapped to SQL/objects |
| **1 – API↔SQL map** | Produce `docs/api-to-sql-map.md`: Endpoint → current query/proc → tables → new repository method | 100 % of endpoints have either a SQL equivalent or an explicit “kept on API” decision — none dropped for being unknown (req #17) |
| **2 – Data layer skeleton** | `ConnectionManager`, `SqlConnectionConfig` + Settings screen, `TransactionRunner`, `SqlErrorMapper`, typed `DbError`, DI wiring | Connect/disconnect/retry/timeout validated against the real server; credential never logged |
| **3 – Auth & session** | Port login to the **existing Atiran logic** found in Phase 0 (same table, same hashing, same role/visitor resolution). No new password table, no fake auth (req #5) | Login, logout, role, visitor id, allowed-customers scope all reproduce current behaviour |
| **4 – Read paths** | Customers, customer groups, cities, products, product groups, price tiers, stock, warehouses, orders/proforma read-back — each with pagination + search (req #7, #8, #9, #13) | Screen-by-screen parity with the API version; no UI change |
| **5 – Write paths** | New customer, proforma/invoice posting **through the existing native procedures** after reading their signature/transaction behaviour (req #10, #11); idempotency guard against double submit | Rollback proven by a forced mid-transaction failure; duplicate submission blocked |
| **6 – Decommission** | Remove only the API code proven unused by the Phase-1 map; keep business rules, validations and access limits (req #2) | Build green, all screens work, dead-code list reviewed by you before deletion |
| **7 – Hardening & tests** | The 27-item test list in §5, plus LeakCanary, rotation, back-navigation, network cut/restore | Signed test report |

---

## 5. Test plan (your §18, made concrete)

1. **Connection** — connect, wrong password, wrong host, host unreachable, port blocked, TLS on/off.
2. **Login** — valid, invalid, locked user, wrong role, empty fields, SQL-injection string as username.
3–7. **User / Visitor / Customer / Customer-scope / Customer-group** — verify a visitor sees *only*
   their own customers (test with two different visitor logins, compare row sets).
8–12. **Product / group / price / inventory / warehouse** — price must equal the current API output
   for the same customer+item (golden-file comparison, not eyeballing).
13–14. **Search / pagination** — search with `%`, `_`, `'`, `--`, unicode/Persian text; page 1..N
   boundary, empty result, 10k-row table.
15. **Customer insert** — duplicate code, required fields, transaction rollback.
16–19. **Proforma** — post, read back, **double-submit twice fast** (idempotency), **kill the
   connection mid-transaction** (pull the network / stop SQL Server) and verify zero partial rows.
20–23. **Logout / network cut / retry / timeout** — clear error message, retry button, no crash.
24–26. **Back navigation / rotation / memory** — no leaked `Connection` (strict-mode + LeakCanary),
   ViewModel survives rotation, no query re-fired on every rotation.
27. **Crash** — no credential, no connection string, no customer PII in the crash payload.

---

## 6. Deliverables checklist (your §20)

| # | Deliverable | Status |
|---|---|---|
| 1 | Changed files | ⛔ needs source (A) |
| 2 | New files | ⛔ needs source (A) |
| 3 | New connection architecture | ✅ §1 above (final naming after A) |
| 4 | All tables used | ⛔ needs C (+B for the “used by” side) |
| 5 | All stored procedures used | ⛔ needs C, Sections 3/4/11 |
| 6 | All functions/views used | ⛔ needs C, Sections 4/8/9 |
| 7 | Previous API → SQL mapping | ⛔ needs A + B |
| 8 | SQL Server settings required | ✅ in `02_least_privilege_login.sql` footer + §3 |
| 9 | Firewall settings required | ✅ same footer (LAN/VPN-restricted 1433, UDP 1434 only if named instance) |
| 10 | Android settings required | ✅ §1 (Keystore prefs, Settings screen) + manifest review after A |
| 11 | SQL permissions required | ✅ template ready; object list filled after C |
| 12 | Possible errors | ✅ §2 + §3 + typed `DbError` design |
| 13 | Tests performed | ⛔ after implementation |
| 14 | Still needs testing on the real server | ✅ §5 items 1, 16–19, 21–23 by definition |

No SQL Server password appears anywhere in this plan, the scripts, or the scanners’ output.
