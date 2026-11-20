package io.vantiq.auth;

import jakarta.validation.constraints.Email;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.Size;

import java.util.List;

public final class AuthDtos {
    private AuthDtos() {}

    public record LoginRequest(
            @Email @NotBlank String email,
            @NotBlank String password
    ) {}

    public record RegisterRequest(
            @NotBlank @Size(max = 180) String organisation_name,
            @NotBlank @Size(max = 180) String name,
            @Email @NotBlank @Size(max = 254) String email,
            @NotBlank @Size(min = 10, max = 128) String password
    ) {}

    public record ChangePasswordRequest(
            @NotBlank String current_password,
            @NotBlank @Size(min = 10, max = 128) String new_password
    ) {}

    public record ForgotPasswordRequest(@Email @NotBlank String email) {}

    public record ResetPasswordRequest(
            @NotBlank @Size(min = 64, max = 64) String token,
            @NotBlank @Size(min = 10, max = 128) String password
    ) {}

    public record OrganisationSummary(Long id, String name, String slug, String status) {}

    public record AuthUser(
            Long id,
            String email,
            String name,
            String status,
            OrganisationSummary organisation,
            List<String> roles,
            List<String> permissions
    ) {}

    public record StatusRequest(@NotBlank String status) {}

    public record RolesRequest(List<Long> role_ids) {}
}
