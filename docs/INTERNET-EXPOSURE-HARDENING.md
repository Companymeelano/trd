# Internet-exposure hardening — Vizitor → SQL Server :1433

Decisions locked in by you:

| Decision | Value | Consequence |
|---|---|---|
| Network path | **Public internet** (phones connect directly to the SQL Server IP) | Highest-risk topology. Everything below is mandatory, not optional. |
| Write scope | **Reads + posting proforma** (`add_sail_pish` and friends) | A credential inside an APK can now *write* to an ERP database. Server-side restriction is the only real control. |
| Driver | To be decided after `01_inventory_atiran2.sql` (Sections 0 and 7) | TLS feasibility depends on it — see §2. |

---

## 1. What “SQL Server directly on the internet” actually means

* Port 1433 (or any fixed port) reachable from everywhere is found by automated scanners within
  hours, and SQL-auth brute force is the standard follow-up attack.
* Microsoft’s own TLS guidance states that SQL Server encrypts the **username/password during
  authentication** even when the rest of the channel is not encrypted — but the **session payload
  (every customer row, every price, every invoice you SELECT) is plaintext unless TLS is on**
  [2](https://learn.microsoft.com/en-us/troubleshoot/sql/database-engine/connect/tls-1-2-support-microsoft-sql-server).
  Over the public internet that means your entire customer database crosses the network readable.
* So for the *internet* topology, `Force Encryption = Yes` is not a “nice to have”; it is the
  baseline. And that collides with the driver problem in §2.

## 2. The driver/TLS collision, and the three ways out

Official jTDS 1.3.1 — the only JDBC driver that realistically runs on Android — does not do
TLS 1.2 [1](https://support.liquibase.com/hc/en-us/articles/42695099387931-Connection-Failure-to-SQL-Server-RDS-After-Enabling-Forced-SSL), and Microsoft’s `mssql-jdbc` does not support Android at all
[1](https://www.daniweb.com/programming/mobile-development/threads/348340/connecting-android-application-to-a-local-ms-sql-server-database). Pick one:

| Option | Encryption | Maintenance risk | Verdict for public internet |
|---|---|---|---|
| **A. Patched jTDS + real certificate + Force Encryption** | TLS at the TDS layer | Unofficial, unmaintained driver patch in a production ERP path [4](https://sourceforge.net/p/jtds/patches/129/) | Acceptable only with a pinned, versioned, internally-built artifact |
| **B. WireGuard/IPsec overlay** (phone joins a VPN; 1433 listens **only** on the VPN/LAN interface) | Tunnel-level, strong | Zero exotic dependencies; standard Android client | **Recommended.** The user still “connects over the internet”, but SQL Server is never publicly reachable |
| **C. TLS-terminating bastion** (stunnel/nginx-stream/HAProxy in front of 1433, or a thin authenticated bridge for the *write* path) | TLS terminated outside SQL Server | One extra small box to patch | Good middle ground; also lets you rate-limit and log per device |

If you stay with a publicly-listening 1433 and plaintext TDS, I will implement it, but I will record
that decision in the code and in this document as an accepted risk — customer PII and pricing over
the open internet with a decompilable credential.

## 3. Server-side checklist (run `03_internet_exposure_audit.sql` first)

**SQL Server**
- [ ] SQL Server Authentication enabled, but **`sa` disabled or renamed**, never used by the app.
- [ ] App login `vizitor_app`: `CHECK_POLICY = ON`, strong unique password, `DEFAULT_DATABASE = Atiran2`.
- [ ] Not a member of `sysadmin` / `securityadmin` / `db_owner` (the audit script proves it).
- [ ] Grants exactly as in `02_least_privilege_login.sql` — SELECT on the read set, EXECUTE only on
      the posting procedures, INSERT/UPDATE only where the app truly writes.
- [ ] `DENY ALTER ON SCHEMA::[dbo]`, `DENY CREATE TABLE/VIEW/PROCEDURE/FUNCTION/TRIGGER` to the app login.
- [ ] Master-scope denies: `xp_cmdshell`, `xp_regread/write`, `sp_OACreate`, `VIEW SERVER STATE`,
      `ALTER ANY LOGIN`, `VIEW ANY DATABASE`.
- [ ] **Force Encryption = Yes** with a valid (ideally CA-issued, not MD5-hashed) certificate.
- [ ] TLS 1.0/1.1 disabled at the OS Schannel level; TLS 1.2 (1.3 where supported) only.
- [ ] Fixed TCP port, **not** 1433 if you must expose it; SQL Browser (UDP 1434) **off**, no named instance.
- [ ] Failed-login auditing on (server audit or the ERRORLOG review in the audit script).
- [ ] `xp_cmdshell` disabled server-wide; CLR disabled unless required.

**Network**
- [ ] Windows Firewall inbound rule scoped to the narrowest possible source. For roaming phones that
      is “any”, which is exactly why Option B/C in §2 exists. If you must allow any:
      ```powershell
      New-NetFirewallRule -DisplayName "SQL Server (Vizitor)" -Direction Inbound `
        -Protocol TCP -LocalPort <port> -Action Allow -Profile Any
      # then add rate limiting / block-on-threshold at the edge firewall or with an IDS
      ```
- [ ] If the server is in a cloud VM: NSG/security-group rule restricted to the VPN subnet, not `0.0.0.0/0`.
- [ ] Consider geo-filtering at the edge if all visitors are inside one country.
- [ ] No port-forwarding of 1434/UDP; no SMB/RDP exposed alongside it.

**Client (Android)**
- [ ] Server/port/database/user/password stored in `EncryptedSharedPreferences` (Keystore-backed),
      editable in a Settings screen, never in `strings.xml`, never in `BuildConfig`, never in git.
- [ ] Password field `inputType="textPassword"`, `android:importantForAutofill="no"`, never logged.
- [ ] `SqlErrorMapper` scrubs credentials/host from every exception message before it reaches a log
      or a crash reporter; `Throwable.message` from JDBC frequently contains the URL.
- [ ] R8/ProGuard on for release; do not obfuscate the JDBC driver classes (keep rules for `net.sourceforge.jtds.**`).
- [ ] `android:allowBackup="false"` (or exclude the prefs file) so the credential is not pulled by `adb backup`.
- [ ] Certificate pinning is **not** applicable to raw TDS — that is another argument for Option B.

## 4. App-side connection design forced by this topology

Mobile + public internet + SQL auth means the connection layer must assume it will be killed:

* **No classic connection pool.** Android reclaims the process; a pooled socket silently dies.
  Use one supervised connection (or at most 2) with a **validation query before reuse**
  (`SELECT 1`) and automatic reconnect on failure.
* **Timeouts everywhere:** connect ~8 s, login ~10 s, read/query ~20 s, transaction ~30 s,
  socket keep-alive on. Nothing unbounded — a roaming phone on a dead tunnel must fail fast.
* **Retry with exponential backoff + jitter**, max 3 attempts, and only for *idempotent* operations.
  Reads retry; **proforma posting never blind-retries** (see §5).
* **Everything off the main thread** (`Dispatchers.IO`); `NetworkOnMainThreadException` otherwise.
* **Explicit connection state** surfaced to the UI (Connected / Connecting / Offline / AuthFailed),
  with a Retry action and a “data may be stale” marker on cached screens (your requirements #13, #14).
* **No `SELECT *`**, explicit column lists, `OFFSET/FETCH` pagination, `TOP`-bounded search.

## 5. Idempotency for proforma posting (mechanism — SQL to be written after Phase 0)

Because the write path goes over a lossy public connection, “did my INSERT arrive?” is a real
question. The design, to be validated against the actual `add_sail_pish` signature:

1. App generates a **client-side draft GUID** when the user starts a proforma; it is persisted locally
   before the first attempt.
2. Submit button is disabled at first tap (double-tap guard) and re-enabled only on a terminal result.
3. One transaction: `autoCommit = false` → call the native posting logic → `commit`; on **any**
   exception `rollback` in `finally`, then map to a typed error.
4. Retry after a timeout is allowed **only after re-reading** whether the document already exists for
   that draft GUID — never a blind second POST.
5. Where the schema permits, a **unique constraint on the draft GUID** is the last line of defence so
   a duplicate cannot exist even if the app logic fails. (Requires an inventory check: does the
   posting path have a place to store it? If not, this becomes an explicit reported gap, not a
   silent hack.)

## 6. What I need next

1. Attach the **Android** zip and the **PHP/IIS API** zip (your choice: *attach both*).
2. Run `discovery/sql/01_inventory_atiran2.sql` → send `atiran2-inventory.txt`
   (Sections 0 and 7 decide the driver; Sections 2/3/4 decide every query).
3. Run `discovery/sql/03_internet_exposure_audit.sql` → send `internet-exposure-audit.txt`.
   It is read-only and tells us how exposed the server is *today*, before we add a client to it.

No password appears in any of these outputs, and none may be pasted into them.
