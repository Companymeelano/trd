/* =====================================================================================
   VIZITOR -> DIRECT SQL SERVER MIGRATION
   STEP 3 : INTERNET-EXPOSURE AUDIT  (READ-ONLY)

   You chose: phones connect over the PUBLIC INTERNET and may POST proforma invoices.
   Before a single line of Android code is written, this script reports how exposed the
   server already is: authentication mode, login policy, who is connected from public
   IPs, whether those sessions are encrypted, failed-login volume, listening ports and
   dangerous server options.

   HOW TO RUN (password prompted, never stored, never printed):
     sqlcmd -S <SQL_SERVER_IP>,<port> -d Atiran2 -U <admin_user> ^
            -i 03_internet_exposure_audit.sql -o internet-exposure-audit.txt -W -w 4096

   NOTE : some sections need VIEW SERVER STATE / securityadmin. They are wrapped in
          TRY/CATCH and simply report "skipped" when the login lacks the right.
   SAFETY : pure SELECT against catalog views and DMVs. No DDL, no DML, no config change.
   ===================================================================================== */

SET NOCOUNT ON;
GO

PRINT '################################################################################';
PRINT '# A. SERVER IDENTITY / AUTHENTICATION MODE';
PRINT '################################################################################';

SELECT
    SERVERPROPERTY('MachineName')                AS MachineName,
    SERVERPROPERTY('InstanceName')               AS InstanceName,
    SERVERPROPERTY('ProductVersion')             AS ProductVersion,
    SERVERPROPERTY('ProductLevel')               AS ProductLevel,
    SERVERPROPERTY('Edition')                    AS Edition,
    SERVERPROPERTY('IsIntegratedSecurityOnly')   AS WindowsAuthOnly_1_Yes_0_Mixed,
    CASE SERVERPROPERTY('IsIntegratedSecurityOnly')
        WHEN 1 THEN 'Windows Authentication only (SQL auth logins cannot be used)'
        ELSE 'MIXED MODE - SQL Server Authentication is enabled (required by the brief, must be hardened)'
    END                                          AS Interpretation;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# B. LOGINS: policy, state, and server-role membership';
PRINT '#    Expected for the app login: CHECK_POLICY=1, is_disabled=0, NO server roles.';
PRINT '################################################################################';

SELECT
    sp.name                              AS LoginName,
    sp.type_desc                         AS LoginType,
    sp.is_disabled                       AS IsDisabled,
    sl.is_policy_checked                 AS PasswordPolicyChecked,
    sl.is_expiration_checked             AS ExpirationChecked,
    sp.default_database_name             AS DefaultDatabase,
    sp.create_date                       AS CreatedDate,
    sp.modify_date                       AS ModifiedDate,
    CASE WHEN sp.name = 'sa' AND sp.is_disabled = 0
         THEN '*** RISK: the built-in sa login is ENABLED ***' ELSE '' END AS SaRisk
FROM sys.server_principals sp
LEFT JOIN sys.sql_logins sl ON sl.principal_id = sp.principal_id
WHERE sp.type IN ('S','U','G')
  AND sp.name NOT LIKE '##%'
ORDER BY sp.type_desc, sp.name;

PRINT '--- B2. Members of dangerous FIXED SERVER ROLES ---';
SELECT
    sr.name  AS ServerRole,
    sp.name  AS MemberLogin,
    sp.type_desc AS MemberType,
    CASE WHEN sr.name IN ('sysadmin','securityadmin')
         THEN '*** must NOT contain the Vizitor app login ***' ELSE '' END AS Note
FROM sys.server_role_members rm
JOIN sys.server_principals sr ON sr.principal_id = rm.role_principal_id
JOIN sys.server_principals sp ON sp.principal_id = rm.member_principal_id
WHERE sr.name IN ('sysadmin','securityadmin','serveradmin','setupadmin','processadmin','dbcreator','diskadmin','bulkadmin')
ORDER BY sr.name, sp.name;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# C. LIVE CONNECTIONS: who is connected, from which IP, and is it ENCRYPTED?';
PRINT '#    encrypt_option = FALSE over a public IP means data crosses the internet in';
PRINT '#    clear text (the login packet itself is always encrypted by SQL Server).';
PRINT '################################################################################';

BEGIN TRY
    SELECT
        c.session_id,
        s.login_name,
        s.host_name,
        s.program_name,
        s.client_interface_name,
        c.client_net_address,
        c.local_net_address,
        c.local_tcp_port,
        c.net_transport,
        c.protocol_type,
        c.encrypt_option,
        c.auth_scheme,
        CASE
            WHEN c.client_net_address IS NULL                        THEN '(shared memory / local)'
            WHEN c.client_net_address IN ('<local machine>','<process id>') THEN 'LOCAL'
            WHEN c.client_net_address LIKE '127.%'                   THEN 'LOOPBACK'
            WHEN c.client_net_address LIKE '10.%'                    THEN 'PRIVATE (10/8)'
            WHEN c.client_net_address LIKE '192.168.%'               THEN 'PRIVATE (192.168/16)'
            WHEN c.client_net_address LIKE '169.254.%'               THEN 'LINK-LOCAL'
            WHEN c.client_net_address LIKE '172.%'
                 AND TRY_CAST(PARSENAME(c.client_net_address, 3) AS int) BETWEEN 16 AND 31
                                                                     THEN 'PRIVATE (172.16/12)'
            ELSE '*** PUBLIC / EXTERNAL IP ***'
        END                                  AS AddressClass,
        s.login_time,
        s.last_request_start_time,
        DB_NAME(s.database_id)               AS CurrentDatabase
    FROM sys.dm_exec_connections c
    JOIN sys.dm_exec_sessions s ON s.session_id = c.session_id
    WHERE s.is_user_process = 1
    ORDER BY AddressClass DESC, s.login_time DESC;

    PRINT '--- C2. Encryption summary ---';
    SELECT
        c.encrypt_option                              AS Encrypted,
        COUNT(*)                                      AS SessionCount,
        SUM(CASE WHEN c.client_net_address NOT LIKE '10.%'
                  AND c.client_net_address NOT LIKE '192.168.%'
                  AND c.client_net_address NOT LIKE '127.%'
                  AND c.client_net_address NOT LIKE '169.254.%'
                  AND c.client_net_address NOT IN ('<local machine>','<process id>')
                 THEN 1 ELSE 0 END)                   AS FromNonPrivateRanges
    FROM sys.dm_exec_connections c
    JOIN sys.dm_exec_sessions s ON s.session_id = c.session_id
    WHERE s.is_user_process = 1
    GROUP BY c.encrypt_option;
END TRY
BEGIN CATCH
    PRINT 'Section C skipped (needs VIEW SERVER STATE): ' + ERROR_MESSAGE();
END CATCH
GO

PRINT '';
PRINT '################################################################################';
PRINT '# D. FAILED LOGINS IN THE ERROR LOG (brute-force indicator)';
PRINT '################################################################################';

BEGIN TRY
    IF OBJECT_ID('tempdb..#errlog') IS NOT NULL DROP TABLE #errlog;
    CREATE TABLE #errlog (LogDate datetime, ProcessInfo nvarchar(64), LogText nvarchar(max));

    INSERT INTO #errlog
    EXEC xp_readerrorlog 0, 1, N'Login failed';

    SELECT
        COUNT(*)            AS FailedLoginEntriesInCurrentLog,
        MIN(LogDate)        AS OldestEntry,
        MAX(LogDate)        AS NewestEntry
    FROM #errlog;

    PRINT '--- D2. Last 30 failed-login entries (no password is ever written to the log) ---';
    SELECT TOP 30 LogDate, ProcessInfo, LogText
    FROM #errlog
    ORDER BY LogDate DESC;

    DROP TABLE #errlog;
END TRY
BEGIN CATCH
    PRINT 'Section D skipped (needs securityadmin/sysadmin for xp_readerrorlog): ' + ERROR_MESSAGE();
END CATCH
GO

PRINT '';
PRINT '################################################################################';
PRINT '# E. WHAT THE SERVER IS ACTUALLY LISTENING ON';
PRINT '################################################################################';

PRINT '--- E1. TCP listener states (IP + port actually bound) ---';
BEGIN TRY
    SELECT
        listener_id,
        ip_address,
        is_ipv4,
        port,
        type_desc,
        state_desc,
        CASE WHEN ip_address IN ('0.0.0.0','::') THEN '*** LISTENING ON ALL INTERFACES ***'
             ELSE 'bound to a specific address' END AS ExposureNote
    FROM sys.dm_tcp_listener_states
    ORDER BY port, ip_address;
END TRY
BEGIN CATCH
    PRINT 'Section E1 skipped: ' + ERROR_MESSAGE();
END CATCH

PRINT '--- E2. Endpoints ---';
SELECT e.name, e.type_desc, e.protocol_desc, e.state_desc,
       te.port, te.is_dynamic_port
FROM sys.endpoints e
LEFT JOIN sys.tcp_endpoints te ON te.endpoint_id = e.endpoint_id
ORDER BY e.name;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# F. DANGEROUS SERVER CONFIGURATION OPTIONS';
PRINT '################################################################################';

SELECT
    name,
    CAST(value AS int)      AS ConfiguredValue,
    CAST(value_in_use AS int) AS ValueInUse,
    CASE
        WHEN name = 'xp_cmdshell'                 AND CAST(value_in_use AS int) = 1 THEN '*** CRITICAL: disable (sp_configure ''xp_cmdshell'',0) ***'
        WHEN name = 'Ole Automation Procedures'   AND CAST(value_in_use AS int) = 1 THEN '*** CRITICAL: sp_OACreate exposed - disable ***'
        WHEN name = 'clr enabled'                 AND CAST(value_in_use AS int) = 1 THEN 'REVIEW: CLR assemblies enabled'
        WHEN name = 'remote access'               AND CAST(value_in_use AS int) = 1 THEN 'REVIEW: legacy remote access'
        WHEN name = 'Agent XPs'                   AND CAST(value_in_use AS int) = 1 THEN 'INFO: SQL Agent XPs on (normal)'
        WHEN name = 'Database Mail XPs'           AND CAST(value_in_use AS int) = 1 THEN 'INFO: Database Mail on'
        WHEN name = 'remote admin connections'    AND CAST(value_in_use AS int) = 1 THEN 'INFO: DAC enabled'
        ELSE ''
    END AS Verdict
FROM sys.configurations
WHERE name IN ('xp_cmdshell','Ole Automation Procedures','clr enabled','remote access',
               'remote admin connections','Agent XPs','Database Mail XPs',
               'Ad Hoc Distributed Queries','scan for startup procs')
ORDER BY name;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# G. DATABASE-LEVEL POSTURE INSIDE Atiran2';
PRINT '################################################################################';

PRINT '--- G1. db_owner members (should be admins only, never the app login) ---';
SELECT dp.name AS DbPrincipal, dp.type_desc AS PrincipalType
FROM sys.database_role_members rm
JOIN sys.database_principals r  ON r.principal_id  = rm.role_principal_id
JOIN sys.database_principals dp ON dp.principal_id = rm.member_principal_id
WHERE r.name = 'db_owner'
ORDER BY dp.name;

PRINT '--- G2. Is the guest account able to access this database? ---';
SELECT name AS Principal, has_dbaccess AS GuestHasAccess,
       CASE WHEN has_dbaccess = 1 THEN '*** RISK: REVOKE CONNECT FROM guest ***' ELSE 'ok' END AS Verdict
FROM sys.database_principals
WHERE name = 'guest';

PRINT '--- G3. Orphaned database users (no matching server login) ---';
SELECT dp.name AS DbUser, dp.type_desc AS UserType, dp.authentication_type_desc AS AuthType
FROM sys.database_principals dp
WHERE dp.type IN ('S','U')
  AND dp.name NOT IN ('dbo','guest','sys','INFORMATION_SCHEMA')
  AND NOT EXISTS (SELECT 1 FROM sys.server_principals sp WHERE sp.sid = dp.sid)
ORDER BY dp.name;

PRINT '--- G4. Permissions currently granted in this database (excluding public defaults) ---';
SELECT
    dp.name                 AS Grantee,
    perm.permission_name    AS Permission,
    perm.state_desc         AS State,
    CASE WHEN perm.major_id = 0 THEN '(DATABASE)'
         ELSE ISNULL(SCHEMA_NAME(o.schema_id) + '.' + o.name, '(other)') END AS OnObject
FROM sys.database_permissions perm
JOIN sys.database_principals dp ON dp.principal_id = perm.grantee_principal_id
LEFT JOIN sys.objects o         ON o.object_id = perm.major_id
WHERE dp.name <> 'public'
  AND NOT (perm.major_id = 0 AND perm.permission_name IN ('CONNECT','VIEW ANY COLUMN ENCRYPTION KEY DEFINITION','VIEW ANY COLUMN MASTER KEY DEFINITION'))
ORDER BY dp.name, OnObject;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# H. IS ANY AUDITING IN PLACE?';
PRINT '################################################################################';

SELECT a.name AS AuditName, a.status_desc AS Status, a.type_desc AS AuditType,
       a.queue_delay AS QueueDelay, a.is_state_changed_by_user_initiated AS UserInitiated
FROM sys.server_audits a;

SELECT s.name AS AuditSpecification, s.is_state_enabled AS Enabled
FROM sys.server_audit_specifications s;

IF NOT EXISTS (SELECT 1 FROM sys.server_audits)
    PRINT '*** No server audit configured: failed logins and app-login activity are NOT being recorded centrally. ***';
GO

PRINT '';
PRINT '################################################################################';
PRINT '# I. VERDICT / NEXT ACTIONS';
PRINT '################################################################################';
PRINT 'Read sections B..H against docs/INTERNET-EXPOSURE-HARDENING.md section 3.';
PRINT '';
PRINT 'Mandatory before an Android client is allowed to connect from the internet:';
PRINT '  1. C : no PUBLIC IP session with encrypt_option = FALSE (=> Force Encryption = Yes,';
PRINT '         or move the phone onto a VPN / TLS-terminating bastion).';
PRINT '  2. B : app login has CHECK_POLICY=1 and zero server roles; sa disabled or renamed.';
PRINT '  3. E : the TCP listener is bound to the narrowest interface you can get away with.';
PRINT '  4. F : xp_cmdshell = 0 and Ole Automation Procedures = 0.';
PRINT '  5. G : app login is not db_owner; guest has no access.';
PRINT '  6. D : failed-login volume is monitored (brute force is continuous on a public 1433).';
PRINT '  7. H : a server audit exists for failed logins and for the app login.';
PRINT '';
PRINT '=== AUDIT COMPLETE ===';
GO
