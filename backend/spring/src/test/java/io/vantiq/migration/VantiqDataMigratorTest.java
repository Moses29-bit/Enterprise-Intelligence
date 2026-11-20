package io.vantiq.migration;

import org.junit.jupiter.api.Test;

import java.io.IOException;
import java.sql.Types;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotEquals;
import static org.junit.jupiter.api.Assertions.assertNull;

class VantiqDataMigratorTest {
    @Test
    void canonicalizesJsonObjectKeysButPreservesArrayOrder() throws IOException {
        assertEquals(
                VantiqDataMigrator.canonicalValue("{\"a\":1,\"b\":{\"c\":2,\"d\":3}}"),
                VantiqDataMigrator.canonicalValue("{\"b\":{\"d\":3,\"c\":2},\"a\":1}"));
        assertNotEquals(
                VantiqDataMigrator.canonicalValue("[1,2]"),
                VantiqDataMigrator.canonicalValue("[2,1]"));
        assertEquals("S{not-json}", VantiqDataMigrator.canonicalValue("{not-json}"));
    }

    @Test
    void rejectsValuesThatWouldOverflowOrRoundDuringImport() {
        var integer = new VantiqDataMigrator.Column(Types.BIGINT, "int8", false, null, true, 19, 0);
        var decimal = new VantiqDataMigrator.Column(Types.NUMERIC, "numeric", false, null, false, 8, 2);

        assertNull(VantiqDataMigrator.validateValue(9223372036854775807L, integer));
        assertEquals("Value is outside the destination integer range",
                VantiqDataMigrator.validateValue("9223372036854775808", integer));
        assertEquals("Value scale exceeds destination numeric scale",
                VantiqDataMigrator.validateValue("12.345", decimal));
    }
}
