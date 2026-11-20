package io.vantiq.migration;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.node.ArrayNode;
import com.fasterxml.jackson.databind.node.ObjectNode;
import org.postgresql.util.PGobject;

import java.io.IOException;
import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.sql.Connection;
import java.sql.DatabaseMetaData;
import java.sql.DriverManager;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Timestamp;
import java.sql.Types;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.Base64;
import java.util.Calendar;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Properties;
import java.util.TimeZone;
import java.util.stream.Collectors;

public final class VantiqDataMigrator {
    private static final ObjectMapper JSON = new ObjectMapper();
    private static final List<String> TABLES = List.of(
            "schema_migrations", "organisations", "users", "roles", "permissions",
            "user_roles", "role_permissions", "branches", "departments", "audit_logs",
            "password_reset_tokens", "login_attempts", "login_history",
            "product_categories", "products", "customers", "suppliers", "sales", "sale_items",
            "expense_categories", "expenses", "invoices", "invoice_items", "credit_notes",
            "credit_note_items", "invoice_refunds", "payments", "stock_movements",
            "purchase_orders", "purchase_order_items", "intelligence_insights", "risk_alerts",
            "forecasts", "forecast_results", "model_versions", "decision_scenarios",
            "scenario_assumptions", "scenario_results", "imported_files", "import_errors",
            "notifications", "employees", "ledger_accounts", "ledger_entries",
            "data_import_batches", "data_import_rows", "documents", "integrations",
            "integration_sync_runs", "outbox_events", "event_notifications"
    );

    private static final Calendar UTC = Calendar.getInstance(TimeZone.getTimeZone("UTC"));

    private VantiqDataMigrator() {
    }

    public static void main(String[] args) throws Exception {
        Map<String, String> flags = parseFlags(args);
        boolean dryRun = !flags.containsKey("apply");
        if (flags.containsKey("dry-run") && flags.containsKey("apply")) {
            throw new IllegalArgumentException("Use either --dry-run or --apply, not both.");
        }

        String sourceUrl = envRequired("VANTIQ_SOURCE_JDBC_URL");
        String destinationUrl = envRequired("VANTIQ_DESTINATION_JDBC_URL");
        Properties sourceProperties = sourceProperties(
                "VANTIQ_SOURCE_DATABASE_USERNAME", "VANTIQ_SOURCE_DATABASE_PASSWORD");
        Properties destinationProperties = destinationProperties(
                "VANTIQ_DESTINATION_DATABASE_USERNAME", "VANTIQ_DESTINATION_DATABASE_PASSWORD");
        Path reportPath = Path.of(flags.getOrDefault("report", "vantiq-migration-report.json"));

        List<TableReport> reports = new ArrayList<>();
        boolean complete;
        try (Connection source = DriverManager.getConnection(sourceUrl, sourceProperties);
             Connection destination = DriverManager.getConnection(destinationUrl, destinationProperties)) {
            source.setReadOnly(true);
            source.setTransactionIsolation(Connection.TRANSACTION_REPEATABLE_READ);
            source.setAutoCommit(false);
            verifyDatabaseProducts(source, destination);
            verifySourceTableInventory(source);
            destination.setReadOnly(dryRun);
            if (!dryRun) destination.setAutoCommit(false);

            for (String table : TABLES) {
                TableReport report;
                try {
                    report = migrateTable(source, destination, table, dryRun);
                } catch (Exception exception) {
                    if (!dryRun) destination.rollback();
                    reports.add(TableReport.failed(table, safeMessage(exception)));
                    break;
                }
                reports.add(report);
                if (!report.status().equals(dryRun ? "READY" : "RECONCILED")) break;
            }

            boolean ready = reports.size() == TABLES.size()
                    && reports.stream().allMatch(report -> report.status().equals(dryRun ? "READY" : "RECONCILED"));
            if (ready && !dryRun) {
                validateDestinationConstraints(destination);
            }
            complete = ready && !dryRun;
            source.commit();
        } catch (Exception exception) {
            reports.add(TableReport.failed("migration", safeMessage(exception)));
            complete = false;
        }

        writeReport(reportPath, new MigrationReport(
                dryRun ? "DRY_RUN" : complete ? "RECONCILED" : "FAILED",
                complete,
                dryRun && reports.size() == TABLES.size()
                        && reports.stream().allMatch(report -> report.status().equals("READY")),
                !dryRun && complete,
                dryRun ? List.of("Read-only dry-run does not execute inserts; PostgreSQL foreign-key and unique "
                        + "constraints are verified only by an apply run against a disposable destination.") : List.of(),
                reports));
        System.out.println(reportPath.toAbsolutePath());
        if (reports.stream().anyMatch(report -> report.status().equals("FAILED"))) System.exit(1);
        if (!dryRun && !complete) System.exit(1);
    }

    static Map<String, String> parseFlags(String[] args) {
        Map<String, String> options = new LinkedHashMap<>();
        for (String arg : args) {
            if (!arg.startsWith("--")) throw new IllegalArgumentException("Unexpected argument.");
            String[] parts = arg.substring(2).split("=", 2);
            String key = parts[0];
            if (!List.of("dry-run", "apply", "report").contains(key)) {
                throw new IllegalArgumentException("Unsupported option --" + key);
            }
            if (!key.equals("report") && parts.length != 1) {
                throw new IllegalArgumentException("--" + key + " does not accept a value.");
            }
            if (options.putIfAbsent(key, parts.length == 2 ? parts[1] : "true") != null) {
                throw new IllegalArgumentException("Duplicate option --" + key);
            }
        }
        if (options.containsKey("report") && options.get("report").isBlank()) {
            throw new IllegalArgumentException("--report requires a path.");
        }
        return options;
    }

    static Object transform(Object value, int destinationSqlType) throws IOException {
        if (value == null) return null;
        if (destinationSqlType == Types.BOOLEAN && value instanceof Number number) {
            return number.intValue() != 0;
        }
        if (destinationSqlType == Types.TIMESTAMP_WITH_TIMEZONE && value instanceof Timestamp timestamp) {
            return timestamp.toInstant().atOffset(ZoneOffset.UTC);
        }
        if (destinationSqlType == Types.OTHER && value instanceof String text) {
            JSON.readTree(text);
            return text;
        }
        if (destinationSqlType == Types.BINARY || destinationSqlType == Types.VARBINARY
                || destinationSqlType == Types.LONGVARBINARY) {
            if (value instanceof byte[] bytes) return bytes;
        }
        return value;
    }

    static String validateValue(Object value, Column column) {
        if (value == null) return column.nullable() ? null : "NULL supplied for a required destination column";

        if (column.sqlType() == Types.TINYINT || column.sqlType() == Types.SMALLINT
                || column.sqlType() == Types.INTEGER || column.sqlType() == Types.BIGINT) {
            BigDecimal number = new BigDecimal(value.toString());
            if (number.stripTrailingZeros().scale() > 0) return "Fractional value cannot fit an integer column";
            BigDecimal minimum;
            BigDecimal maximum;
            if (column.sqlType() == Types.TINYINT) {
                minimum = BigDecimal.valueOf(Byte.MIN_VALUE);
                maximum = BigDecimal.valueOf(Byte.MAX_VALUE);
            } else if (column.sqlType() == Types.SMALLINT) {
                minimum = BigDecimal.valueOf(Short.MIN_VALUE);
                maximum = BigDecimal.valueOf(Short.MAX_VALUE);
            } else if (column.sqlType() == Types.INTEGER) {
                minimum = BigDecimal.valueOf(Integer.MIN_VALUE);
                maximum = BigDecimal.valueOf(Integer.MAX_VALUE);
            } else {
                minimum = BigDecimal.valueOf(Long.MIN_VALUE);
                maximum = BigDecimal.valueOf(Long.MAX_VALUE);
            }
            if (number.compareTo(minimum) < 0 || number.compareTo(maximum) > 0) {
                return "Value is outside the destination integer range";
            }
        } else if (column.sqlType() == Types.NUMERIC || column.sqlType() == Types.DECIMAL) {
            BigDecimal number = new BigDecimal(value.toString());
            BigDecimal normalized = number.stripTrailingZeros();
            int fractionalDigits = Math.max(0, normalized.scale());
            int integerDigits = Math.max(0, normalized.precision() - normalized.scale());
            if (column.decimalDigits() >= 0
                    && fractionalDigits > column.decimalDigits()) {
                return "Value scale exceeds destination numeric scale";
            }
            if (column.size() > 0 && (number.precision() > column.size()
                    || integerDigits > column.size() - Math.max(0, column.decimalDigits()))) {
                return "Value precision exceeds destination numeric precision";
            }
        } else if ((column.sqlType() == Types.CHAR || column.sqlType() == Types.VARCHAR
                || column.sqlType() == Types.NCHAR || column.sqlType() == Types.NVARCHAR)
                && column.size() > 0 && value.toString().length() > column.size()) {
            return "Text value exceeds destination column length";
        }
        return null;
    }

    private static TableReport migrateTable(
            Connection source,
            Connection destination,
            String table,
            boolean dryRun
    ) throws Exception {
        if (!tableExists(source, table) || !tableExists(destination, table)) {
            return TableReport.failed(table, "Source or destination table is missing.");
        }

        Map<String, Column> sourceColumns = columns(source, table);
        Map<String, Column> destinationColumns = columns(destination, table);
        List<String> missingDestinationColumns = sourceColumns.keySet().stream()
                .filter(name -> !destinationColumns.containsKey(name))
                .toList();
        if (!missingDestinationColumns.isEmpty()) {
            return TableReport.failed(table, "Source columns missing in destination: " + missingDestinationColumns);
        }
        List<String> missingRequiredSourceColumns = destinationColumns.entrySet().stream()
                .filter(entry -> !sourceColumns.containsKey(entry.getKey()))
                .filter(entry -> !entry.getValue().nullable()
                        && entry.getValue().defaultValue() == null
                        && !entry.getValue().identity())
                .map(Map.Entry::getKey)
                .toList();
        if (!missingRequiredSourceColumns.isEmpty()) {
            return TableReport.failed(table, "Required destination columns have no source/default: "
                    + missingRequiredSourceColumns);
        }

        List<String> sourcePrimaryKey = primaryKey(source, table);
        List<String> destinationPrimaryKey = primaryKey(destination, table);
        if (sourcePrimaryKey.isEmpty() || !sourcePrimaryKey.equals(destinationPrimaryKey)) {
            return TableReport.failed(table, "Primary-key mismatch; source="
                    + sourcePrimaryKey + ", destination=" + destinationPrimaryKey);
        }

        List<String> columns = new ArrayList<>(sourceColumns.keySet());
        List<ColumnMapping> columnMappings = columns.stream()
                .map(name -> new ColumnMapping(name,
                        sourceColumns.get(name).typeName(),
                        destinationColumns.get(name).typeName(),
                        sourceColumns.get(name).nullable(),
                        destinationColumns.get(name).nullable(),
                        sourceColumns.get(name).defaultValue(),
                        destinationColumns.get(name).defaultValue(),
                        sourceColumns.get(name).size(),
                        destinationColumns.get(name).size(),
                        sourceColumns.get(name).decimalDigits(),
                        destinationColumns.get(name).decimalDigits(),
                        conversion(sourceColumns.get(name), destinationColumns.get(name))))
                .toList();
        List<String> targetOnlyColumns = destinationColumns.keySet().stream()
                .filter(name -> !sourceColumns.containsKey(name))
                .toList();
        long sourceCount = count(source, table);
        long matched = 0;
        long inserted = 0;
        List<String> failedKeys = new ArrayList<>();
        List<String> rowWarnings = new ArrayList<>();
        MessageDigest fingerprint = sha256();
        String selectSql = "SELECT " + quoteColumns(columns) + " FROM " + quote(table)
                + " ORDER BY " + quoteColumns(sourcePrimaryKey);
        String insertSql = insertSql(table, columns,
                destinationColumns.values().stream().anyMatch(Column::identity));

        try (PreparedStatement query = source.prepareStatement(selectSql);
             ResultSet rows = query.executeQuery()) {
            while (rows.next()) {
                Map<String, Object> values = new LinkedHashMap<>();
                for (int i = 0; i < columns.size(); i++) {
                    Column column = sourceColumns.get(columns.get(i));
                    Object raw = column.sqlType() == Types.TIMESTAMP
                            ? rows.getTimestamp(i + 1, (Calendar) UTC.clone())
                            : rows.getObject(i + 1);
                    values.put(columns.get(i), transform(raw, destinationColumns.get(columns.get(i)).sqlType()));
                }

                byte[] rowHash = rowHash(columns, values);
                fingerprint.update(rowHash);
                Object[] key = sourcePrimaryKey.stream().map(values::get).toArray();
                String validationError = null;
                for (String column : columns) {
                    validationError = validateValue(values.get(column), destinationColumns.get(column));
                    if (validationError != null) {
                        rowWarnings.add(keyLabel(sourcePrimaryKey, key) + ": " + column + ": " + validationError);
                        break;
                    }
                }
                if (validationError != null) {
                    failedKeys.add(keyLabel(sourcePrimaryKey, key));
                    continue;
                }
                boolean newlyInserted = false;
                if (!dryRun) {
                    try (PreparedStatement insert = destination.prepareStatement(insertSql)) {
                        for (int i = 0; i < columns.size(); i++) {
                            bind(insert, i + 1, values.get(columns.get(i)),
                                    destinationColumns.get(columns.get(i)).sqlType());
                        }
                        newlyInserted = insert.executeUpdate() == 1;
                    }
                }

                byte[] destinationHash = destinationRowHash(
                        destination, table, columns, sourcePrimaryKey, key, destinationColumns);
                if (destinationHash == null) {
                    if (!dryRun) {
                        failedKeys.add(keyLabel(sourcePrimaryKey, key));
                        rowWarnings.add(keyLabel(sourcePrimaryKey, key)
                                + ": no destination row exists after insert/conflict handling");
                    }
                } else if (MessageDigest.isEqual(rowHash, destinationHash)) {
                    matched++;
                    if (newlyInserted) inserted++;
                } else {
                    failedKeys.add(keyLabel(sourcePrimaryKey, key));
                }
            }
        }

        String sourceFingerprint = hex(fingerprint.digest());
        long destinationCount = count(destination, table);
        long missing = Math.max(0, sourceCount - matched);
        if (!dryRun) {
            upsertCheckpoint(destination, table, sourceFingerprint, sourceCount, matched);
            resetSequence(destination, table, destinationColumns);
        }
        String status = failedKeys.isEmpty() && (dryRun || sourceCount == matched)
                ? (dryRun ? "READY" : "RECONCILED") : "FAILED";
        if (!dryRun) {
            if (status.equals("RECONCILED")) destination.commit();
            else destination.rollback();
        }
        List<String> warnings = new ArrayList<>();
        warnings.addAll(rowWarnings);
        if (dryRun) {
            warnings.add("Row checks do not prove that destination foreign-key and unique constraints accept inserts.");
        }
        if (destinationCount > matched) {
            warnings.add("Destination contains " + (destinationCount - matched)
                    + " rows not reconciled against this source table; they were preserved.");
        }
        return new TableReport(
                table, sourceCount, destinationCount, matched, inserted, failedKeys.size(), missing,
                sourceFingerprint, String.join(",", sourcePrimaryKey),
                failedKeys.isEmpty() ? (dryRun ? "PARTIAL_DRY_RUN" : "RECONCILED_BY_ROW_HASH") : "FAILED",
                dryRun ? "NOT_TESTED_DRY_RUN" : "ENFORCED_BY_POSTGRES_IMPORT",
                dryRun ? "NOT_TESTED_DRY_RUN" : "ENFORCED_BY_POSTGRES_IMPORT", status, failedKeys,
                targetOnlyColumns, columnMappings, warnings);
    }

    private static byte[] destinationRowHash(
            Connection destination,
            String table,
            List<String> columns,
            List<String> primaryKey,
            Object[] key,
            Map<String, Column> destinationColumns
    ) throws Exception {
        String predicates = primaryKey.stream().map(name -> quote(name) + " = ?").collect(Collectors.joining(" AND "));
        String sql = "SELECT " + quoteColumns(columns) + " FROM " + quote(table) + " WHERE " + predicates;
        try (PreparedStatement statement = destination.prepareStatement(sql)) {
            for (int i = 0; i < key.length; i++) {
                bind(statement, i + 1, key[i], destinationColumns.get(primaryKey.get(i)).sqlType());
            }
            try (ResultSet result = statement.executeQuery()) {
                if (!result.next()) return null;
                Map<String, Object> values = new LinkedHashMap<>();
                for (int i = 0; i < columns.size(); i++) {
                    Object value = result.getObject(i + 1);
                    if (value instanceof OffsetDateTime dateTime) value = Timestamp.from(dateTime.toInstant());
                    else if (value instanceof PGobject pgObject) value = pgObject.getValue();
                    values.put(columns.get(i), transform(value, destinationColumns.get(columns.get(i)).sqlType()));
                }
                return rowHash(columns, values);
            }
        }
    }

    private static String insertSql(
            String table,
            List<String> columns,
            boolean hasIdentity
    ) {
        String placeholders = String.join(", ", java.util.Collections.nCopies(columns.size(), "?"));
        return "INSERT INTO " + quote(table) + " (" + quoteColumns(columns) + ") "
                + (hasIdentity ? "OVERRIDING SYSTEM VALUE " : "")
                + "VALUES (" + placeholders + ") ON CONFLICT DO NOTHING";
    }

    private static void bind(PreparedStatement statement, int position, Object value, int sqlType)
            throws SQLException {
        if (value == null) {
            statement.setNull(position, sqlType);
        } else if (sqlType == Types.OTHER) {
            statement.setObject(position, value, Types.OTHER);
        } else if (sqlType == Types.TINYINT || sqlType == Types.SMALLINT
                || sqlType == Types.INTEGER || sqlType == Types.BIGINT) {
            BigDecimal number = new BigDecimal(value.toString());
            if (sqlType == Types.TINYINT) statement.setByte(position, number.byteValueExact());
            else if (sqlType == Types.SMALLINT) statement.setShort(position, number.shortValueExact());
            else if (sqlType == Types.INTEGER) statement.setInt(position, number.intValueExact());
            else statement.setLong(position, number.longValueExact());
        } else if (value instanceof byte[] bytes) {
            statement.setBytes(position, bytes);
        } else if (value instanceof OffsetDateTime offsetDateTime) {
            statement.setObject(position, offsetDateTime);
        } else {
            statement.setObject(position, value);
        }
    }

    private static byte[] rowHash(List<String> columns, Map<String, Object> values) throws Exception {
        StringBuilder canonical = new StringBuilder();
        for (String column : columns) {
            Object value = values.get(column);
            String normalized = canonicalValue(value);
            canonical.append(column.length()).append(':').append(column)
                    .append(normalized.length()).append(':').append(normalized);
        }
        return sha256().digest(canonical.toString().getBytes(StandardCharsets.UTF_8));
    }

    static String canonicalValue(Object value) throws IOException {
        if (value == null) return "N";
        if (value instanceof BigDecimal decimal) return "D" + decimal.stripTrailingZeros().toPlainString();
        if (value instanceof Number number) return "D" + new BigDecimal(number.toString()).stripTrailingZeros().toPlainString();
        if (value instanceof Boolean bool) return "B" + bool;
        if (value instanceof byte[] bytes) return "X" + Base64.getEncoder().encodeToString(bytes);
        if (value instanceof Timestamp timestamp) return "T" + timestamp.toInstant().toString();
        if (value instanceof OffsetDateTime dateTime) return "T" + dateTime.toInstant().toString();
        if (value instanceof String text) {
            String normalized = text;
            if (text.startsWith("{") || text.startsWith("[")) {
                try {
                    normalized = JSON.writeValueAsString(canonicalNode(JSON.readTree(text)));
                } catch (IOException notJsonText) {
                    normalized = text;
                }
            }
            return "S" + normalized;
        }
        return "S" + value.toString();
    }

    private static JsonNode canonicalNode(JsonNode value) {
        if (value.isObject()) {
            ObjectNode canonical = JSON.createObjectNode();
            List<String> fieldNames = new ArrayList<>();
            value.fieldNames().forEachRemaining(fieldNames::add);
            fieldNames.sort(String::compareTo);
            for (String fieldName : fieldNames) canonical.set(fieldName, canonicalNode(value.get(fieldName)));
            return canonical;
        }
        if (value.isArray()) {
            ArrayNode canonical = JSON.createArrayNode();
            value.forEach(item -> canonical.add(canonicalNode(item)));
            return canonical;
        }
        return value;
    }

    private static void upsertCheckpoint(
            Connection destination,
            String table,
            String fingerprint,
            long sourceCount,
            long importedCount
    ) throws SQLException {
        try (PreparedStatement statement = destination.prepareStatement("""
                INSERT INTO migration_checkpoints
                    (source_table, source_fingerprint, source_record_count, imported_record_count, completed, updated_at)
                VALUES (?, ?, ?, ?, TRUE, now())
                ON CONFLICT (source_table) DO UPDATE SET
                    source_fingerprint = EXCLUDED.source_fingerprint,
                    source_record_count = EXCLUDED.source_record_count,
                    imported_record_count = EXCLUDED.imported_record_count,
                    completed = TRUE,
                    updated_at = now()
                """)) {
            statement.setString(1, table);
            statement.setString(2, fingerprint);
            statement.setLong(3, sourceCount);
            statement.setLong(4, importedCount);
            statement.executeUpdate();
        }
    }

    private static void resetSequence(
            Connection destination,
            String table,
            Map<String, Column> columns
    ) throws SQLException {
        Column idColumn = columns.get("id");
        if (idColumn == null || !idColumn.identity()) return;
        try (PreparedStatement sequence = destination.prepareStatement(
                "SELECT pg_get_serial_sequence(?, 'id')")) {
            sequence.setString(1, table);
            try (ResultSet result = sequence.executeQuery()) {
                if (!result.next() || result.getString(1) == null) {
                    throw new SQLException("Identity sequence is missing for " + table);
                }
                String sequenceName = result.getString(1);
                String sql = "SELECT setval(?::regclass, "
                        + "COALESCE((SELECT max(id) FROM " + quote(table) + "), 1), "
                        + "(SELECT count(*) FROM " + quote(table) + ") > 0)";
                try (PreparedStatement reset = destination.prepareStatement(sql)) {
                    reset.setString(1, sequenceName);
                    reset.executeQuery().close();
                }
            }
        }
    }

    private static void validateDestinationConstraints(Connection destination) throws SQLException {
        try (PreparedStatement statement = destination.prepareStatement("""
                SELECT c.conname
                FROM pg_constraint c
                JOIN pg_namespace n ON n.oid = c.connamespace
                WHERE n.nspname = current_schema()
                  AND c.contype IN ('c', 'f', 'p', 'u')
                  AND NOT c.convalidated
                LIMIT 1
                """);
             ResultSet result = statement.executeQuery()) {
            if (result.next()) {
                throw new SQLException("Destination constraint is not validated: " + result.getString(1));
            }
        }
    }

    private static Map<String, Column> columns(Connection connection, String table) throws SQLException {
        String schema = connection.getMetaData().getDatabaseProductName().toLowerCase(Locale.ROOT).contains("postgresql")
                ? connection.getSchema() : null;
        try (ResultSet result = connection.getMetaData().getColumns(connection.getCatalog(), schema, table, null)) {
            Map<String, Column> columns = new LinkedHashMap<>();
            while (result.next()) {
                String name = result.getString("COLUMN_NAME").toLowerCase(Locale.ROOT);
                columns.put(name, new Column(
                        result.getInt("DATA_TYPE"),
                        result.getString("TYPE_NAME"),
                        result.getInt("NULLABLE") == DatabaseMetaData.columnNullable,
                        result.getString("COLUMN_DEF"),
                        "YES".equalsIgnoreCase(result.getString("IS_AUTOINCREMENT")),
                        result.getInt("COLUMN_SIZE"),
                        result.getInt("DECIMAL_DIGITS")));
            }
            return columns;
        }
    }

    private static List<String> primaryKey(Connection connection, String table) throws SQLException {
        String schema = connection.getMetaData().getDatabaseProductName().toLowerCase(Locale.ROOT).contains("postgresql")
                ? connection.getSchema() : null;
        try (ResultSet result = connection.getMetaData().getPrimaryKeys(connection.getCatalog(), schema, table)) {
            Map<Short, String> sequence = new LinkedHashMap<>();
            while (result.next()) sequence.put(result.getShort("KEY_SEQ"), result.getString("COLUMN_NAME").toLowerCase(Locale.ROOT));
            return sequence.entrySet().stream().sorted(Map.Entry.comparingByKey()).map(Map.Entry::getValue).toList();
        }
    }

    private static boolean tableExists(Connection connection, String table) throws SQLException {
        DatabaseMetaData metadata = connection.getMetaData();
        String schema = metadata.getDatabaseProductName().toLowerCase(Locale.ROOT).contains("postgresql")
                ? connection.getSchema() : null;
        try (ResultSet result = metadata.getTables(connection.getCatalog(), schema, table, new String[]{"TABLE"})) {
            return result.next();
        }
    }

    private static void verifyDatabaseProducts(Connection source, Connection destination) throws SQLException {
        String sourceProduct = source.getMetaData().getDatabaseProductName().toLowerCase(Locale.ROOT);
        String destinationProduct = destination.getMetaData().getDatabaseProductName().toLowerCase(Locale.ROOT);
        if (!(sourceProduct.contains("mariadb") || sourceProduct.contains("mysql"))) {
            throw new SQLException("Source must be MariaDB or MySQL.");
        }
        if (!destinationProduct.contains("postgresql")) {
            throw new SQLException("Destination must be PostgreSQL.");
        }
    }

    private static void verifySourceTableInventory(Connection source) throws SQLException {
        List<String> discovered = new ArrayList<>();
        try (ResultSet tables = source.getMetaData().getTables(source.getCatalog(), null, "%", new String[]{"TABLE"})) {
            while (tables.next()) discovered.add(tables.getString("TABLE_NAME").toLowerCase(Locale.ROOT));
        }
        List<String> unlisted = discovered.stream().filter(name -> !TABLES.contains(name)).sorted().toList();
        List<String> missing = TABLES.stream().filter(name -> !discovered.contains(name)).toList();
        if (!unlisted.isEmpty() || !missing.isEmpty()) {
            throw new SQLException("Source table inventory mismatch; missing=" + missing + ", unlisted=" + unlisted);
        }
    }

    private static long count(Connection connection, String table) throws SQLException {
        try (PreparedStatement statement = connection.prepareStatement("SELECT count(*) FROM " + quote(table));
             ResultSet result = statement.executeQuery()) {
            result.next();
            return result.getLong(1);
        }
    }

    private static Properties sourceProperties(String usernameVariable, String passwordVariable) {
        Properties properties = new Properties();
        properties.setProperty("user", envRequired(usernameVariable));
        properties.setProperty("password", envRequired(passwordVariable));
        properties.setProperty("serverTimezone", "UTC");
        properties.setProperty("useUnicode", "true");
        properties.setProperty("characterEncoding", "UTF-8");
        String sslMode = envRequired("VANTIQ_SOURCE_SSL_MODE").toUpperCase(Locale.ROOT);
        if (!List.of("DISABLE", "TRUST", "VERIFY_CA", "VERIFY_FULL").contains(sslMode)) {
            throw new IllegalArgumentException("VANTIQ_SOURCE_SSL_MODE must be a supported MariaDB SSL mode.");
        }
        properties.setProperty("sslMode", sslMode);
        return properties;
    }

    private static Properties destinationProperties(String usernameVariable, String passwordVariable) {
        Properties properties = new Properties();
        properties.setProperty("user", envRequired(usernameVariable));
        properties.setProperty("password", envRequired(passwordVariable));
        properties.setProperty("sslmode", "require");
        return properties;
    }

    private static String conversion(Column source, Column destination) {
        String sourceType = source.typeName().toLowerCase(Locale.ROOT);
        String destinationType = destination.typeName().toLowerCase(Locale.ROOT);
        if (sourceType.contains("unsigned")) return "UNSIGNED_RANGE_CHECK_REQUIRED";
        if (sourceType.equals("enum")) return "ENUM_TO_CHECKED_TEXT";
        if (sourceType.equals("json")) return "JSON_TO_JSONB";
        if (sourceType.contains("blob") || sourceType.contains("binary")) return "BINARY_TO_BYTEA";
        if (sourceType.contains("datetime") || sourceType.equals("timestamp")) return "UTC_TO_TIMESTAMPTZ";
        if (sourceType.contains("decimal") && destinationType.contains("numeric")) return "EXACT_NUMERIC";
        if (sourceType.equals(destinationType)) return "IDENTITY";
        return sourceType + "_TO_" + destinationType;
    }

    private static String envRequired(String name) {
        String value = System.getenv(name);
        if (value == null || value.isBlank()) throw new IllegalArgumentException("Required environment variable is missing: " + name);
        return value;
    }

    private static String keyLabel(List<String> columns, Object[] values) {
        StringBuilder key = new StringBuilder();
        for (int i = 0; i < columns.size(); i++) {
            String value = String.valueOf(values[i]);
            key.append(columns.get(i).length()).append(':').append(columns.get(i))
                    .append(value.length()).append(':').append(value);
        }
        try {
            return "pk-sha256:" + hex(sha256().digest(key.toString().getBytes(StandardCharsets.UTF_8)));
        } catch (NoSuchAlgorithmException exception) {
            throw new IllegalStateException("SHA-256 is unavailable.", exception);
        }
    }

    private static String quoteColumns(List<String> columns) {
        return columns.stream().map(VantiqDataMigrator::quote).collect(Collectors.joining(", "));
    }

    private static String quote(String value) {
        if (!value.matches("[A-Za-z_][A-Za-z0-9_]*")) throw new IllegalArgumentException("Invalid SQL identifier.");
        return "\"" + value + "\"";
    }

    private static MessageDigest sha256() throws NoSuchAlgorithmException {
        return MessageDigest.getInstance("SHA-256");
    }

    private static String hex(byte[] value) {
        return java.util.HexFormat.of().formatHex(value);
    }

    private static String safeMessage(Exception exception) {
        return exception instanceof IllegalArgumentException
                ? exception.getMessage()
                : "Migration failed with " + exception.getClass().getSimpleName() + ".";
    }

    private static void writeReport(Path path, MigrationReport report) throws IOException {
        Path parent = path.toAbsolutePath().getParent();
        if (parent != null) Files.createDirectories(parent);
        JSON.writerWithDefaultPrettyPrinter().writeValue(path.toFile(), report);
    }

    record Column(int sqlType, String typeName, boolean nullable, String defaultValue, boolean identity,
                  int size, int decimalDigits) {
    }

    private record TableReport(
            String sourceTable,
            long sourceRecordCount,
            long destinationRecordCount,
            long importedRecordCount,
            long newlyInsertedRecordCount,
            long failedRecordCount,
            long missingRecordCount,
            String sourceFingerprint,
            String primaryKeyColumns,
            String primaryKeyValidation,
            String foreignKeyValidation,
            String uniqueConstraintValidation,
            String status,
            List<String> failedPrimaryKeys,
            List<String> targetOnlyColumns,
            List<ColumnMapping> columns,
            List<String> warnings
    ) {
        static TableReport failed(String table, String warning) {
            return new TableReport(table, 0, 0, 0, 0, 0, 0, null, "",
                    "NOT_CHECKED", "NOT_CHECKED", "NOT_CHECKED", "FAILED",
                    List.of(), List.of(), List.of(), List.of(warning));
        }
    }

    private record ColumnMapping(String column, String sourceType, String destinationType,
                                 boolean sourceNullable, boolean destinationNullable,
                                 String sourceDefault, String destinationDefault,
                                 int sourceSize, int destinationSize, int sourceScale, int destinationScale,
                                 String conversion) {
    }

    private record MigrationReport(String status, boolean migrationCompleted, boolean preflightPassed,
                                   boolean dataConstraintsChecked, List<String> preflightLimitations,
                                   List<TableReport> tables) {
    }
}
