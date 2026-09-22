/* =====================================================================================
   VIZITOR -> DIRECT SQL SERVER MIGRATION
   STEP 1 : EVIDENCE / INVENTORY  (READ-ONLY)

   Target DB : Atiran2
   Purpose   : Produce ground truth about the REAL schema so that no table name,
               column name, proc signature or query has to be guessed.

   HOW TO RUN (password is prompted, never stored, never printed):
     sqlcmd -S <SQL_SERVER_IP>,1433 -d Atiran2 -U <read_only_user> ^
            -i 01_inventory_atiran2.sql -o atiran2-inventory.txt ^
            -W -w 4096 -y 0
   or: open in SSMS -> Results -> Save As -> Text/CSV

   SAFETY : only catalog views are read (sys.*). No DDL, no DML, no writes.
   ===================================================================================== */

SET NOCOUNT ON;
GO

PRINT '################################################################################';
PRINT '# SECTION 0 : CONNECTION / SERVER CONTEXT';
PRINT '################################################################################';

SELECT
    @@VERSION                                   AS SqlVersionFull,
    SERVERPROPERTY('ProductVersion')            AS ProductVersion,
    SERVERPROPERTY('ProductMajorVersion')       AS ProductMajorVersion,
    SERVERPROPERTY('ProductLevel')              AS ProductLevel,
    SERVERPROPERTY('Edition')                   AS Edition,
    SERVERPROPERTY('Collation')                 AS ServerCollation,
    DB_NAME()                                   AS CurrentDatabase;

SELECT
    d.name                          AS DatabaseName,
    d.compatibility_level           AS CompatibilityLevel,
    d.collation_name                AS DbCollation,          /* case-sensitivity matters for codegen */
    d.recovery_model_desc           AS RecoveryModel,
    d.is_read_only                  AS IsReadOnly,
    d.state_desc                    AS State
FROM sys.databases d
WHERE d.name = DB_NAME();

/* Is this session encrypted (TLS)? Critical for the jTDS / mssql-jdbc driver decision. */
SELECT
    c.session_id,
    c.net_transport,
    c.protocol_type,
    c.encrypt_option,               /* TRUE = TLS in use */
    c.auth_scheme,
    c.client_net_address,
    c.local_net_address,
    c.local_tcp_port
FROM sys.dm_exec_connections c
WHERE c.session_id = @@SPID;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# SECTION 1 : THE OBJECTS THE MIGRATION BRIEF NAMES -> DO THEY EXIST, AND AS WHAT?';
PRINT '################################################################################';

IF OBJECT_ID('tempdb..#wanted') IS NOT NULL DROP TABLE #wanted;
CREATE TABLE #wanted (name sysname NOT NULL PRIMARY KEY);

/* Tables named in the brief */
INSERT INTO #wanted (name) VALUES
    ('sys_users'),('Roles'),('visitors'),('CUSTOMERS'),('custgroup'),('CITYS'),
    ('inventory'),('kagroup'),('forosh_price'),('inventory_anbars'),
    ('Inventory_Anbars_Variety'),('osystems'),('sailfact'),('subsailfact'),
    ('ka_act'),('new_cust');

/* Additional objects mentioned elsewhere in the brief (customer/auth layer) */
INSERT INTO #wanted (name) VALUES
    ('sys_cus'),('cust_act');

/* Objects that may be tables OR procedures - resolved below, never assumed */
INSERT INTO #wanted (name) VALUES
    ('subsailfact_pish'),('sailfact_pish'),
    ('add_sail_pish'),('AddInvoice'),('FixMojodi');

/* Functions */
INSERT INTO #wanted (name) VALUES
    ('fn_GetVisitorsByRoleId');

SELECT
    w.name                                          AS RequestedName,
    ISNULL(o.type_desc, '*** NOT FOUND ***')        AS ActualType,
    ISNULL(s.name, '-')                             AS SchemaName,
    o.object_id                                     AS ObjectId,
    o.create_date                                   AS CreatedDate,
    o.modify_date                                   AS ModifiedDate
FROM #wanted w
LEFT JOIN sys.objects o ON UPPER(o.name) = UPPER(w.name) AND o.is_ms_shipped = 0
LEFT JOIN sys.schemas s ON s.schema_id  = o.schema_id
ORDER BY CASE WHEN o.object_id IS NULL THEN 0 ELSE 1 END, w.name;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# SECTION 2 : EXACT COLUMNS (name / type / nullability / default / identity)';
PRINT '#             for every requested object that is a TABLE or VIEW';
PRINT '################################################################################';

SELECT
    s.name                          AS SchemaName,
    o.name                          AS ObjectName,
    o.type_desc                     AS ObjectType,
    c.column_id                     AS ColId,
    c.name                          AS ColumnName,
    t.name                          AS DataType,
    CASE
        WHEN t.name IN ('varchar','char','varbinary','binary')
            THEN CASE WHEN c.max_length = -1 THEN 'MAX' ELSE CAST(c.max_length AS varchar(10)) END
        WHEN t.name IN ('nvarchar','nchar')
            THEN CASE WHEN c.max_length = -1 THEN 'MAX' ELSE CAST(c.max_length/2 AS varchar(10)) END
        ELSE CAST(c.max_length AS varchar(10))
    END                             AS LengthOrSize,
    c.precision                     AS Precision,
    c.scale                         AS Scale,
    c.is_nullable                   AS IsNullable,
    c.is_identity                   AS IsIdentity,
    ic.seed_value                   AS IdentitySeed,
    ic.increment_value              AS IdentityIncrement,
    dc.definition                   AS DefaultDefinition,
    c.is_computed                   AS IsComputed
FROM sys.objects o
JOIN sys.schemas s   ON s.schema_id   = o.schema_id
JOIN sys.columns c   ON c.object_id   = o.object_id
JOIN sys.types   t   ON t.user_type_id = c.user_type_id
LEFT JOIN sys.identity_columns ic ON ic.object_id = c.object_id AND ic.column_id = c.column_id
LEFT JOIN sys.default_constraints dc ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id
WHERE o.is_ms_shipped = 0
  AND o.type IN ('U','V')
  AND EXISTS (SELECT 1 FROM #wanted w WHERE UPPER(w.name) = UPPER(o.name))
ORDER BY o.name, c.column_id;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# SECTION 3 : PROCEDURE / FUNCTION SIGNATURES (parameters, types, OUTPUT, defaults)';
PRINT '#             -> required before calling ANY stored procedure from Android';
PRINT '################################################################################';

SELECT
    s.name                          AS SchemaName,
    o.name                          AS ModuleName,
    o.type_desc                     AS ModuleType,
    ISNULL(p.name, '(returns)')     AS ParamName,
    p.parameter_id                  AS ParamOrder,
    t.name                          AS DataType,
    CASE
        WHEN t.name IN ('nvarchar','nchar') THEN CASE WHEN p.max_length=-1 THEN 'MAX' ELSE CAST(p.max_length/2 AS varchar(10)) END
        ELSE CAST(p.max_length AS varchar(10))
    END                             AS LengthOrSize,
    p.precision                     AS Precision,
    p.scale                         AS Scale,
    p.is_output                     AS IsOutput,
    p.is_result                     AS IsResult,
    p.has_default_value             AS HasDefault,
    p.default_value                 AS DefaultValue   /* sql_variant; often NULL unless elevated */
FROM sys.objects o
JOIN sys.schemas s ON s.schema_id = o.schema_id
LEFT JOIN sys.parameters p ON p.object_id = o.object_id
LEFT JOIN sys.types t      ON t.user_type_id = p.user_type_id
WHERE o.is_ms_shipped = 0
  AND o.type IN ('P','FN','IF','TF','AF')
  AND EXISTS (SELECT 1 FROM #wanted w WHERE UPPER(w.name) = UPPER(o.name))
ORDER BY o.name, p.parameter_id;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# SECTION 4 : FULL SOURCE DEFINITION of the requested procedures / functions / views';
PRINT '#             (this is where the REAL business logic lives: pricing tiers, visitor';
PRINT '#              scope, invoice posting). Run sqlcmd with -y 0 so it is not truncated.';
PRINT '################################################################################';

SELECT
    s.name          AS SchemaName,
    o.name          AS ModuleName,
    o.type_desc     AS ModuleType,
    LEN(m.definition) AS DefinitionLength,
    m.definition    AS Definition
FROM sys.sql_modules m
JOIN sys.objects o  ON o.object_id = m.object_id
JOIN sys.schemas s  ON s.schema_id = o.schema_id
WHERE o.is_ms_shipped = 0
  AND EXISTS (SELECT 1 FROM #wanted w WHERE UPPER(w.name) = UPPER(o.name))
ORDER BY o.name;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# SECTION 5 : PRIMARY KEYS / FOREIGN KEYS / INDEXES of the requested objects';
PRINT '################################################################################';

PRINT '--- 5a. PRIMARY KEYS ---';
SELECT
    o.name              AS TableName,
    i.name              AS PrimaryKeyName,
    c.name              AS ColumnName,
    ic.key_ordinal      AS KeyOrdinal
FROM sys.indexes i
JOIN sys.objects o          ON o.object_id = i.object_id
JOIN sys.index_columns ic   ON ic.object_id = i.object_id AND ic.index_id = i.index_id
JOIN sys.columns c          ON c.object_id = ic.object_id AND c.column_id = ic.column_id
WHERE i.is_primary_key = 1
  AND EXISTS (SELECT 1 FROM #wanted w WHERE UPPER(w.name) = UPPER(o.name))
ORDER BY o.name, ic.key_ordinal;

PRINT '--- 5b. FOREIGN KEYS (relationship map) ---';
SELECT
    fk.name                             AS ForeignKeyName,
    tp.name                             AS ParentTable,
    cp.name                             AS ParentColumn,
    tr.name                             AS ReferencedTable,
    cr.name                             AS ReferencedColumn,
    fk.delete_referential_action_desc   AS OnDelete,
    fk.update_referential_action_desc   AS OnUpdate,
    fk.is_disabled                      AS IsDisabled
FROM sys.foreign_keys fk
JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
JOIN sys.objects tp ON tp.object_id = fk.parent_object_id
JOIN sys.objects tr ON tr.object_id = fk.referenced_object_id
JOIN sys.columns cp ON cp.object_id = fkc.parent_object_id    AND cp.column_id = fkc.parent_column_id
JOIN sys.columns cr ON cr.object_id = fkc.referenced_object_id AND cr.column_id = fkc.referenced_column_id
WHERE EXISTS (SELECT 1 FROM #wanted w WHERE UPPER(w.name) = UPPER(tp.name))
   OR EXISTS (SELECT 1 FROM #wanted w WHERE UPPER(w.name) = UPPER(tr.name))
ORDER BY tp.name, fk.name;

PRINT '--- 5c. INDEXES (needed for pagination / search performance) ---';
SELECT
    o.name                  AS TableName,
    i.name                  AS IndexName,
    i.type_desc             AS IndexType,
    i.is_unique             AS IsUnique,
    STUFF((SELECT ', ' + c.name + CASE WHEN ic2.is_descending_key=1 THEN ' DESC' ELSE '' END
           FROM sys.index_columns ic2
           JOIN sys.columns c ON c.object_id = ic2.object_id AND c.column_id = ic2.column_id
           WHERE ic2.object_id = i.object_id AND ic2.index_id = i.index_id AND ic2.is_included_column = 0
           ORDER BY ic2.key_ordinal
           FOR XML PATH('')), 1, 2, '')     AS KeyColumns,
    STUFF((SELECT ', ' + c.name
           FROM sys.index_columns ic2
           JOIN sys.columns c ON c.object_id = ic2.object_id AND c.column_id = ic2.column_id
           WHERE ic2.object_id = i.object_id AND ic2.index_id = i.index_id AND ic2.is_included_column = 1
           FOR XML PATH('')), 1, 2, '')     AS IncludedColumns
FROM sys.indexes i
JOIN sys.objects o ON o.object_id = i.object_id
WHERE i.type > 0
  AND EXISTS (SELECT 1 FROM #wanted w WHERE UPPER(w.name) = UPPER(o.name))
ORDER BY o.name, i.index_id;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# SECTION 6 : ROW COUNTS + SIZE (drives pagination / caching decisions)';
PRINT '################################################################################';

SELECT
    s.name                                              AS SchemaName,
    o.name                                              AS TableName,
    SUM(CASE WHEN ps.index_id IN (0,1) THEN ps.row_count ELSE 0 END) AS RowEstimate,
    CAST(SUM(ps.reserved_page_count) * 8.0 / 1024 AS decimal(18,2))  AS ReservedMB
FROM sys.dm_db_partition_stats ps
JOIN sys.objects o ON o.object_id = ps.object_id
JOIN sys.schemas s ON s.schema_id = o.schema_id
WHERE o.is_ms_shipped = 0 AND o.type = 'U'
  AND EXISTS (SELECT 1 FROM #wanted w WHERE UPPER(w.name) = UPPER(o.name))
GROUP BY s.name, o.name
ORDER BY RowEstimate DESC;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# SECTION 7 : DRIVER COMPATIBILITY RISK - DATA TYPES IN USE';
PRINT '#             jTDS (the only JDBC driver that realistically runs on Android) does';
PRINT '#             NOT support: datetime2, datetimeoffset, date, time, sql_variant,';
PRINT '#             spatial/hierarchyid, TLS 1.2 (official build).';
PRINT '#             This section tells us whether a direct-JDBC design is even viable.';
PRINT '################################################################################';

PRINT '--- 7a. All data types used anywhere in user tables/views ---';
SELECT t.name AS DataType, COUNT(*) AS ColumnCount
FROM sys.columns c
JOIN sys.types   t ON t.user_type_id = c.user_type_id
JOIN sys.objects o ON o.object_id = c.object_id AND o.is_ms_shipped = 0 AND o.type IN ('U','V')
GROUP BY t.name
ORDER BY ColumnCount DESC;

PRINT '--- 7b. RISKY types inside the tables Vizitor actually needs ---';
SELECT
    o.name  AS TableName,
    c.name  AS ColumnName,
    t.name  AS DataType,
    CASE t.name
        WHEN 'datetime2'       THEN 'jTDS: NOT SUPPORTED (needs patch/fork or cast to datetime)'
        WHEN 'datetimeoffset'  THEN 'jTDS: NOT SUPPORTED'
        WHEN 'date'            THEN 'jTDS: NOT SUPPORTED'
        WHEN 'time'            THEN 'jTDS: NOT SUPPORTED'
        WHEN 'sql_variant'     THEN 'jTDS: NOT SUPPORTED'
        WHEN 'geography'       THEN 'jTDS: NOT SUPPORTED'
        WHEN 'geometry'        THEN 'jTDS: NOT SUPPORTED'
        WHEN 'hierarchyid'     THEN 'jTDS: NOT SUPPORTED'
        WHEN 'xml'             THEN 'jTDS: partial'
        ELSE 'check collation/length only'
    END AS Risk
FROM sys.columns c
JOIN sys.types   t ON t.user_type_id = c.user_type_id
JOIN sys.objects o ON o.object_id = c.object_id AND o.is_ms_shipped = 0
WHERE t.name IN ('datetime2','datetimeoffset','date','time','sql_variant','geography','geometry','hierarchyid','xml')
  AND EXISTS (SELECT 1 FROM #wanted w WHERE UPPER(w.name) = UPPER(o.name))
ORDER BY o.name, c.column_id;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# SECTION 8 : DEPENDENCY CROSS-REFERENCE';
PRINT '#             Which views / procs / functions / triggers mention each wanted object?';
PRINT '#             -> discovers the Atiran logic we must preserve (pricing, scope, posting)';
PRINT '################################################################################';

PRINT '--- 8a. PRECISE dependencies (sys.sql_expression_dependencies) ---';
SELECT
    sm.name         AS ModuleName,
    sm.type_desc    AS ModuleType,
    ref.name        AS ReferencedObject,
    ref.type_desc   AS ReferencedType,
    d.is_caller_dependent AS IsCallerDependent
FROM sys.sql_expression_dependencies d
JOIN sys.objects sm  ON sm.object_id  = d.referencing_id
JOIN sys.objects ref ON ref.object_id = d.referenced_id
WHERE EXISTS (SELECT 1 FROM #wanted w WHERE UPPER(w.name) = UPPER(ref.name))
ORDER BY ref.name, sm.name;

PRINT '--- 8b. FUZZY text search (catches dynamic SQL; short names like "Roles" over-match) ---';
IF OBJECT_ID('tempdb..#xref') IS NOT NULL DROP TABLE #xref;
CREATE TABLE #xref (ReferencedName sysname, ModuleName sysname, ModuleType varchar(60), SchemaName sysname);

DECLARE @n sysname;
DECLARE cur CURSOR LOCAL FAST_FORWARD FOR SELECT name FROM #wanted;
OPEN cur;
FETCH NEXT FROM cur INTO @n;
WHILE @@FETCH_STATUS = 0
BEGIN
    INSERT INTO #xref (ReferencedName, ModuleName, ModuleType, SchemaName)
    SELECT @n, o.name, o.type_desc, s.name
    FROM sys.sql_modules m
    JOIN sys.objects o ON o.object_id = m.object_id
    JOIN sys.schemas s ON s.schema_id = o.schema_id
    WHERE o.is_ms_shipped = 0
      AND m.definition LIKE '%' + @n + '%';
    FETCH NEXT FROM cur INTO @n;
END
CLOSE cur; DEALLOCATE cur;

SELECT ReferencedName, SchemaName, ModuleName, ModuleType
FROM #xref
ORDER BY ReferencedName, ModuleType, ModuleName;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# SECTION 9 : WHOLE-DATABASE OBJECT CENSUS + "VISITOR-ISH" NAME SEARCH';
PRINT '#             so nothing used by Vizitor is missed just because the brief did not';
PRINT '#             list it.';
PRINT '################################################################################';

PRINT '--- 9a. Counts by type ---';
SELECT o.type_desc, COUNT(*) AS ObjectCount
FROM sys.objects o
WHERE o.is_ms_shipped = 0
GROUP BY o.type_desc
ORDER BY ObjectCount DESC;

PRINT '--- 9b. Objects whose name looks related to Vizitor / Atiran sales flow ---';
SELECT s.name AS SchemaName, o.name AS ObjectName, o.type_desc AS ObjectType, o.modify_date AS ModifiedDate
FROM sys.objects o
JOIN sys.schemas s ON s.schema_id = o.schema_id
WHERE o.is_ms_shipped = 0
  AND (
        o.name LIKE '%vis%'      OR o.name LIKE '%visit%'
     OR o.name LIKE '%cust%'     OR o.name LIKE '%moshtari%'
     OR o.name LIKE '%sail%'     OR o.name LIKE '%sale%'
     OR o.name LIKE '%pish%'     OR o.name LIKE '%factor%'  OR o.name LIKE '%invoice%'
     OR o.name LIKE '%price%'    OR o.name LIKE '%forosh%'  OR o.name LIKE '%ghemat%'
     OR o.name LIKE '%anbar%'    OR o.name LIKE '%inventory%' OR o.name LIKE '%stock%' OR o.name LIKE '%mojodi%'
     OR o.name LIKE '%ka%'       OR o.name LIKE '%kala%'    OR o.name LIKE '%product%'
     OR o.name LIKE '%user%'     OR o.name LIKE '%role%'    OR o.name LIKE '%access%' OR o.name LIKE '%auth%'
     OR o.name LIKE '%city%'     OR o.name LIKE '%shahr%'   OR o.name LIKE '%group%'
     OR o.name LIKE '%osystem%'  OR o.name LIKE '%sys\_%' ESCAPE '\'
      )
ORDER BY o.type_desc, o.name;
GO

PRINT '';
PRINT '################################################################################';
PRINT '# SECTION 10 : SECURITY POSTURE (what a restricted app login would inherit)';
PRINT '################################################################################';

BEGIN TRY
    PRINT '--- 10a. Database principals and role memberships ---';
    SELECT
        dp.name         AS DbPrincipal,
        dp.type_desc    AS PrincipalType,
        ISNULL(r.name,'') AS MemberOfRole
    FROM sys.database_principals dp
    LEFT JOIN sys.database_role_members rm ON rm.member_principal_id = dp.principal_id
    LEFT JOIN sys.database_principals r    ON r.principal_id = rm.role_principal_id
    WHERE dp.type IN ('S','U','R','G')
    ORDER BY dp.type_desc, dp.name;

    PRINT '--- 10b. Explicit database-level permissions ---';
    SELECT
        dp.name             AS Grantee,
        perm.permission_name AS Permission,
        perm.state_desc     AS State,
        ISNULL(o.name,'(database)') AS OnObject
    FROM sys.database_permissions perm
    JOIN sys.database_principals dp ON dp.principal_id = perm.grantee_principal_id
    LEFT JOIN sys.objects o         ON o.object_id = perm.major_id
    WHERE dp.name NOT IN ('public')
    ORDER BY dp.name, perm.permission_name;
END TRY
BEGIN CATCH
    PRINT 'Section 10 skipped (insufficient permission to read security catalog): ' + ERROR_MESSAGE();
END CATCH
GO

PRINT '';
PRINT '################################################################################';
PRINT '# SECTION 11 : EXISTING MODULES THAT WRITE INVOICES / ORDERS';
PRINT '#             (find the native posting logic that must be reused, not re-invented)';
PRINT '################################################################################';

SELECT s.name AS SchemaName, o.name AS ModuleName, o.type_desc AS ModuleType, o.modify_date AS ModifiedDate
FROM sys.objects o
JOIN sys.schemas s ON s.schema_id = o.schema_id
WHERE o.is_ms_shipped = 0
  AND o.type IN ('P','FN','IF','TF','TR')
  AND (
        o.name LIKE '%add%sail%'   OR o.name LIKE '%sail%add%'
     OR o.name LIKE '%add%fact%'   OR o.name LIKE '%invoice%'
     OR o.name LIKE '%pish%'       OR o.name LIKE '%fix%mojodi%' OR o.name LIKE '%mojodi%'
     OR o.name LIKE '%new%cust%'   OR o.name LIKE '%ka%act%'     OR o.name LIKE '%cust%act%'
      )
ORDER BY o.name;
GO

PRINT '';
PRINT '=== INVENTORY COMPLETE ===';
PRINT 'Send the generated .txt back. Do NOT edit it. Do NOT paste any password into it.';
GO
