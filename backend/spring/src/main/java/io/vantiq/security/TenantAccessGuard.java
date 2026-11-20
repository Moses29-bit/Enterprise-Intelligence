package io.vantiq.security;

import org.springframework.security.access.AccessDeniedException;

public final class TenantAccessGuard {
    public void requireSameOrganisation(long authenticatedOrganisationId, long resourceOrganisationId) {
        if (authenticatedOrganisationId < 1 || resourceOrganisationId < 1
                || authenticatedOrganisationId != resourceOrganisationId) {
            throw new AccessDeniedException("The resource is not available to this organisation.");
        }
    }
}
