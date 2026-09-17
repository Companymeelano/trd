# Discovery kit — Vizitor → SQL Server migration

Read-only tooling. It changes nothing in your project and prints no passwords.

Run the three steps below, then send me the outputs (or the source itself). Everything else in the
migration depends on this evidence — see `docs/VIZITOR-SQL-MIGRATION-PLAN.md` §0.

```
discovery/
├── sql/
│   ├── 01_inventory_atiran2.sql           # READ-ONLY schema/object/definition dump from Atiran2
│   ├── 02_least_privilege_login.sql       # TEMPLATE: restricted login + grants (fill after step 1)
│   └── 03_internet_exposure_audit.sql     # READ-ONLY: how exposed the server already is
└── tools/
    ├── Scan-AndroidProject.ps1            # READ-ONLY inventory of the Android app
    └── Scan-PhpApi.ps1                    # READ-ONLY inventory of the PHP/IIS API
```

---

## Step 1 — Inventory the real SQL Server schema (most important)

Use a **read-only** SQL login if you have one. `sqlcmd` prompts for the password, so it never ends
up in a file, in the command history or in the output.

```powershell
cd <repo>\discovery\sql

sqlcmd -S <SQL_SERVER_IP>,1433 -d Atiran2 -U <read_only_user> `
       -i .\01_inventory_atiran2.sql -o .\atiran2-inventory.txt `
       -W -w 4096 -y 0
```

`-y 0` matters: without it the procedure **definitions (Section 4) are truncated**, and those
definitions are exactly where the pricing-tier, visitor-scope and invoice-posting logic lives.

Or in SSMS: open the script → run → *Results* → *Save Results As…* (Text). Send me `atiran2-inventory.txt`.

What it answers:

| Section | Question it settles |
|---|---|
| 0 | SQL Server version, collation (case-sensitive?), **is the connection TLS-encrypted?** |
| 1 | Do `sys_users`, `visitors`, `CUSTOMERS`, `forosh_price`, `add_sail_pish`, `fn_GetVisitorsByRoleId`, … actually exist, and as *what* (table / view / proc)? |
| 2 | **Exact column names, types, nullability, identity, defaults** — so nothing is guessed |
| 3 | **Exact proc/function signatures** (params, types, OUTPUT, defaults) — required before any `EXEC` |
| 4 | Full body of those procs/functions/views — the native Atiran logic we must preserve |
| 5 | PK / FK / indexes — relationship map + what pagination and search can use |
| 6 | Row counts — which lists need paging vs. can be cached |
| 7 | **Driver-compatibility risk**: `datetime2` / `date` / `time` / `datetimeoffset` / `sql_variant` break jTDS (the only realistic Android JDBC driver) |
| 8 | Which modules reference each object — discovers pricing/scope/posting logic the brief did not name |
| 9 | Whole-DB census + name search, so no Vizitor-related object is missed |
| 10 | Existing principals/permissions — what a restricted login must avoid inheriting |
| 11 | The invoice/order-writing modules that must be reused, not re-invented |

## Step 2 — Inventory the Android project

```powershell
cd <repo>\discovery\tools

powershell -ExecutionPolicy Bypass -File .\Scan-AndroidProject.ps1 `
    -ProjectRoot "$env:USERPROFILE\Desktop\Vizitor-2.17.1-Luxury-AI-Final" `
    -OutDir      "$env:USERPROFILE\Desktop\vizitor-discovery"
```

Outputs `android-inventory.md` + 7 CSVs: class/category inventory (Activity, Fragment, ViewModel,
Repository, DataSource, DAO, ApiService, HttpClient, Entity, DTO, Login, Session, Cache, DI, Room),
Retrofit endpoint list with verbs/paths/params, inline SQL, URLs & connection strings,
**hardcoded-secret locations (masked)**, Gradle/SDK/dependency table, manifest permissions &
components, and suspicious `res/values` strings.

## Step 3 — Inventory the PHP/IIS API

```powershell
powershell -ExecutionPolicy Bypass -File .\Scan-PhpApi.ps1 `
    -ApiRoot "C:\inetpub\wwwroot\<vizitor-api-folder>" `
    -OutDir  "$env:USERPROFILE\Desktop\vizitor-discovery"
```

Outputs `api-inventory.md` + 6 CSVs: endpoint → params → tables/procs/functions, every SQL
statement found, the object-usage summary, JSON response field names (the DTO contract),
**auth/session evidence** (how Atiran passwords are actually verified — hashed? plain? which
column?), and connection-config locations with values masked.

This is what produces the *Endpoint → SQL → Table → new Repository* map required by §17, from
evidence instead of inference.

## Step 3b — Audit how exposed the server already is (you chose: public internet)

```powershell
sqlcmd -S <SQL_SERVER_IP>,<port> -d Atiran2 -U <admin_user> `
       -i .\03_internet_exposure_audit.sql -o .\internet-exposure-audit.txt -W -w 4096
```

Read-only. Reports: auth mode, login policy + server-role membership, **live sessions classified as
PRIVATE vs PUBLIC IP with their `encrypt_option`**, failed-login volume from the ERRORLOG, the actual
TCP listeners (is it bound to `0.0.0.0`?), dangerous server options (`xp_cmdshell`, OLE Automation),
`db_owner` members, guest access, orphaned users, existing permissions, and whether a server audit
exists. Sections needing `VIEW SERVER STATE` / `securityadmin` are skipped gracefully.

Interpret it with `docs/INTERNET-EXPOSURE-HARDENING.md` §3 — that checklist is mandatory before an
Android client is allowed to reach the database from the internet, especially since the app will
also **post proforma invoices**.

## Step 4 — Restricted SQL login (only after Step 1)

`sql/02_least_privilege_login.sql` is a **template**: its grant list must be filled from the
Step-1 output (Sections 1 / 9b / 11). It takes the password as a sqlcmd variable so it is never
written into the file:

```powershell
sqlcmd -S <SQL_SERVER_IP>,1433 -d Atiran2 -U <admin_user> `
       -v SqlLoginPassword="<strong password>" `
       -i .\02_least_privilege_login.sql -o .\grants-result.txt
```

Its footer also contains the SQL Server Configuration Manager and Windows Firewall settings
(TCP/IP enabled, fixed port 1433, SQL auth mode, Force Encryption, subnet-restricted inbound rule).

---

## Then I can start writing code

With A (Android source) + B (API source) + C (inventory output) I deliver: the new data layer,
the per-screen repository swap, the Settings/connection screen, the transaction-safe posting path,
the changed/new file lists and the test report — with no invented table or column names.
