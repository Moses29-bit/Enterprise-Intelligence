package io.vantiq.security;

import org.junit.jupiter.api.Test;
import org.springframework.security.access.AccessDeniedException;

import static org.junit.jupiter.api.Assertions.assertDoesNotThrow;
import static org.junit.jupiter.api.Assertions.assertThrows;

class TenantAccessGuardTest {
    private final TenantAccessGuard guard = new TenantAccessGuard();

    @Test
    void permitsOnlyTheAuthenticatedOrganisation() {
        assertDoesNotThrow(() -> guard.requireSameOrganisation(12, 12));
        assertThrows(AccessDeniedException.class, () -> guard.requireSameOrganisation(12, 13));
        assertThrows(AccessDeniedException.class, () -> guard.requireSameOrganisation(0, 12));
    }
}
