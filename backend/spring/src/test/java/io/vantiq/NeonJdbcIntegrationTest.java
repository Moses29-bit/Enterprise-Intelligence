package io.vantiq;

import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.condition.EnabledIfEnvironmentVariable;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.dao.DataIntegrityViolationException;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.transaction.PlatformTransactionManager;
import org.springframework.transaction.support.TransactionTemplate;

import java.math.BigDecimal;
import java.util.UUID;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

@SpringBootTest
@EnabledIfEnvironmentVariable(named = "VANTIQ_NEON_INTEGRATION", matches = "true")
class NeonJdbcIntegrationTest {
    @Autowired
    private JdbcTemplate jdbcTemplate;

    @Autowired
    private PlatformTransactionManager transactionManager;

    @Test
    void neonCommitRollbackRelationshipAndConstraintIntegration() {
        String suffix = UUID.randomUUID().toString();
        String organisationSlug = "neon-test-" + suffix;
        String branchCode = "BR-" + suffix.substring(0, 8);
        String departmentCode = "DP-" + suffix.substring(0, 8);

        TransactionTemplate template = new TransactionTemplate(transactionManager);

        Long organisationId = template.execute(status -> jdbcTemplate.queryForObject(
                "INSERT INTO organisations(name, slug, status) "
                        + "VALUES (?, ?, 'active') RETURNING id",
                Long.class,
                "Neon synthetic organisation - " + suffix,
                organisationSlug));
        assertNotNull(organisationId);

        template.executeWithoutResult(status -> {
            Long branchId = jdbcTemplate.queryForObject(
                    "INSERT INTO branches(organisation_id, name, code) "
                            + "VALUES (?, ?, ?) RETURNING id",
                    Long.class,
                    organisationId,
                    "Neon synthetic branch",
                    branchCode);
            jdbcTemplate.update(
                    "INSERT INTO departments(organisation_id, branch_id, name, code) "
                            + "VALUES (?, ?, ?, ?)",
                    organisationId,
                    branchId,
                    "Neon synthetic department",
                    departmentCode);

            jdbcTemplate.update(
                    "INSERT INTO audit_logs(organisation_id, action_type, entity_name, details) "
                            + "VALUES (?, 'integration_test', 'synthetic', ?::jsonb)",
                    organisationId,
                    "{\"currency\":\"GHS\",\"label\":\"UTF-8: \u4f01\u4e1a\",\"ok\":true}");

            BigDecimal unitCost = new BigDecimal("1234.56");
            jdbcTemplate.update(
                    "INSERT INTO product_categories(organisation_id, name) "
                            + "VALUES (?, ?)",
                    organisationId,
                    "Neon synthetic category " + suffix);
            Long categoryId = jdbcTemplate.queryForObject(
                    "SELECT id FROM product_categories "
                            + "WHERE organisation_id = ? AND name = ?",
                    Long.class,
                    organisationId,
                    "Neon synthetic category " + suffix);
            jdbcTemplate.update(
                    "INSERT INTO products(organisation_id, category_id, name, sku, unit_cost, selling_price) "
                            + "VALUES (?, ?, ?, ?, ?, ?)",
                    organisationId,
                    categoryId,
                    "Neon synthetic product",
                    "SKU-" + suffix.substring(0, 12),
                    unitCost,
                    new BigDecimal("1500.00"));
        });

        Integer relationshipCount = jdbcTemplate.queryForObject(
                "SELECT count(*) FROM departments d "
                        + "JOIN branches b ON b.organisation_id=d.organisation_id AND b.id=d.branch_id "
                        + "WHERE d.organisation_id=?",
                Integer.class,
                organisationId);
        assertEquals(1, relationshipCount);

        BigDecimal storedUnitCost = jdbcTemplate.queryForObject(
                "SELECT unit_cost FROM products WHERE organisation_id=?",
                BigDecimal.class,
                organisationId);
        assertEquals(new BigDecimal("1234.56"), storedUnitCost);

        assertTrue(jdbcTemplate.queryForObject(
                "SELECT EXISTS(SELECT 1 FROM audit_logs "
                        + "WHERE organisation_id=? AND details->>'ok'='true')",
                Boolean.class,
                organisationId));

        assertThrows(DataIntegrityViolationException.class, () ->
                template.execute(status -> jdbcTemplate.update(
                        "INSERT INTO organisations(name, slug, status) "
                                + "VALUES (?, ?, 'active')",
                        "Duplicate synthetic organisation",
                        organisationSlug)));

        assertThrows(DataIntegrityViolationException.class, () ->
                template.execute(status -> jdbcTemplate.update(
                        "INSERT INTO branches(organisation_id, name, code) "
                                + "VALUES (?, 'Invalid branch', ?)",
                        Long.MAX_VALUE,
                        "INVALID-" + suffix)));

        final String rollbackSlug = "neon-rollback-" + suffix;
        template.execute(status -> {
            jdbcTemplate.update(
                    "INSERT INTO organisations(name, slug, status) "
                            + "VALUES ('Rollback synthetic organisation', ?, 'active')",
                    rollbackSlug);
            status.setRollbackOnly();
            return null;
        });
        assertFalse(jdbcTemplate.queryForObject(
                "SELECT EXISTS(SELECT 1 FROM organisations WHERE slug=?)",
                Boolean.class,
                rollbackSlug));

        template.executeWithoutResult(status -> {
            jdbcTemplate.update("DELETE FROM products WHERE organisation_id=?", organisationId);
            jdbcTemplate.update("DELETE FROM product_categories WHERE organisation_id=?", organisationId);
            jdbcTemplate.update("DELETE FROM audit_logs WHERE organisation_id=?", organisationId);
            jdbcTemplate.update("DELETE FROM departments WHERE organisation_id=?", organisationId);
            jdbcTemplate.update("DELETE FROM branches WHERE organisation_id=?", organisationId);
            jdbcTemplate.update("DELETE FROM organisations WHERE id=?", organisationId);
        });
        assertFalse(jdbcTemplate.queryForObject(
                "SELECT EXISTS(SELECT 1 FROM organisations WHERE id=?)",
                Boolean.class,
                organisationId));
    }
}
