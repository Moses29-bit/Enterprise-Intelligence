package io.vantiq.auth;

import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpSession;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.security.authentication.BadCredentialsException;
import org.springframework.security.authentication.LockedException;
import org.springframework.security.core.context.SecurityContext;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.authentication.UsernamePasswordAuthenticationToken;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.security.web.context.HttpSessionSecurityContextRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import org.springframework.web.server.ResponseStatusException;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.time.OffsetDateTime;
import java.util.ArrayList;
import java.util.HexFormat;
import java.util.List;
import java.util.Locale;
import java.util.UUID;

@Service
public class AuthService {
    private final OrganisationRepository organisations;
    private final UserRepository users;
    private final PasswordEncoder passwordEncoder;
    private final JdbcTemplate jdbcTemplate;
    private final String ipHashSecret;

    public AuthService(
            OrganisationRepository organisations,
            UserRepository users,
            PasswordEncoder passwordEncoder,
            JdbcTemplate jdbcTemplate,
            @Value("${app.security.ip-hash-secret:${JWT_SECRET:}}") String ipHashSecret
    ) {
        this.organisations = organisations;
        this.users = users;
        this.passwordEncoder = passwordEncoder;
        this.jdbcTemplate = jdbcTemplate;
        this.ipHashSecret = ipHashSecret;
    }

    @Transactional
    public AuthDtos.AuthUser register(AuthDtos.RegisterRequest request) {
        String email = request.email().trim().toLowerCase(Locale.ROOT);
        if (users.findByEmail(email).isPresent()) {
            throw new ResponseStatusException(HttpStatus.CONFLICT, "email_unavailable");
        }

        String slugBase = request.organisation_name().trim().toLowerCase(Locale.ROOT)
                .replaceAll("[^a-z0-9]+", "-");
        slugBase = slugBase.replaceAll("^-|-$", "");
        if (slugBase.isBlank()) slugBase = "organisation";
        String slug = slugBase.substring(0, Math.min(100, slugBase.length())) + "-" + UUID.randomUUID().toString().substring(0, 8);

        Organisation organisation = organisations.save(new Organisation(
                request.organisation_name().trim(), slug));
        User user = users.save(new User(
                organisation, email, passwordEncoder.encode(request.password()), request.name().trim()));

        jdbcTemplate.update(
                "INSERT INTO roles(organisation_id, name, description, is_system, created_at) "
                        + "VALUES (?, 'Organisation Owner', 'Organisation administrator', false, now())",
                organisation.getId());
        Long roleId = jdbcTemplate.queryForObject(
                "SELECT id FROM roles WHERE organisation_id=? AND name='Organisation Owner'",
                Long.class, organisation.getId());
        jdbcTemplate.update("INSERT INTO user_roles(user_id, role_id, created_at) VALUES (?, ?, now())",
                user.getId(), roleId);
        assignOwnerPermissions(roleId);
        return publicUser(user);
    }

    public AuthDtos.AuthUser login(AuthDtos.LoginRequest request, HttpServletRequest httpRequest) {
        String email = request.email().trim().toLowerCase(Locale.ROOT);
        enforceThrottle(email, httpRequest);

        User user = users.findByEmail(email).orElse(null);
        Organisation organisation = user == null ? null : user.getOrganisation();
        boolean organisationActive = organisation == null || "active".equals(organisation.getStatus());
        boolean valid = user != null
                && "active".equals(user.getStatus())
                && organisationActive
                && passwordEncoder.matches(request.password(), user.getPasswordHash());

        if (!valid) {
            recordFailure(email, httpRequest);
            throw new BadCredentialsException("invalid_credentials");
        }

        clearThrottle(email, httpRequest);
        user.setLastLoginAt(OffsetDateTime.now());
        users.save(user);

        HttpSession oldSession = httpRequest.getSession(false);
        if (oldSession != null) oldSession.invalidate();
        HttpSession session = httpRequest.getSession(true);

        AuthDtos.AuthUser publicUser = publicUser(user);
        List<SimpleGrantedAuthority> authorities = publicUser.permissions().stream()
                .map(SimpleGrantedAuthority::new)
                .toList();
        authorities = new ArrayList<>(authorities);

        UsernamePasswordAuthenticationToken authentication =
                new UsernamePasswordAuthenticationToken(publicUser.email(), null, authorities);
        authentication.setDetails(new AuthPrincipal(user.getId(), organisation == null ? null : organisation.getId()));
        SecurityContext context = SecurityContextHolder.createEmptyContext();
        context.setAuthentication(authentication);
        SecurityContextHolder.setContext(context);
        session.setAttribute(HttpSessionSecurityContextRepository.SPRING_SECURITY_CONTEXT_KEY, context);
        session.setAttribute("VANTIQ_USER_ID", user.getId());
        session.setAttribute("VANTIQ_ORGANISATION_ID", organisation == null ? null : organisation.getId());
        return publicUser;
    }

    public AuthDtos.AuthUser currentUser(HttpServletRequest request) {
        HttpSession session = request.getSession(false);
        if (session == null) throw new ResponseStatusException(HttpStatus.UNAUTHORIZED, "Authentication is required.");
        Object userIdValue = session.getAttribute("VANTIQ_USER_ID");
        if (!(userIdValue instanceof Number number)) {
            throw new ResponseStatusException(HttpStatus.UNAUTHORIZED, "Authentication is required.");
        }
        User user = users.findById(number.longValue()).orElse(null);
        if (user == null) throw new ResponseStatusException(HttpStatus.UNAUTHORIZED, "Authentication is required.");
        if (!"active".equals(user.getStatus())) {
            throw new ResponseStatusException(HttpStatus.UNAUTHORIZED, "Account is not active.");
        }
        return publicUser(user);
    }

    @Transactional
    public void changePassword(Long userId, AuthDtos.ChangePasswordRequest request) {
        User user = users.findById(userId).orElseThrow(
                () -> new ResponseStatusException(HttpStatus.UNAUTHORIZED, "Authentication is required."));
        if (!passwordEncoder.matches(request.current_password(), user.getPasswordHash())) {
            throw new ResponseStatusException(HttpStatus.UNPROCESSABLE_ENTITY, "current_password_invalid");
        }
        user.setPasswordHash(passwordEncoder.encode(request.new_password()));
        user.setSessionVersion(user.getSessionVersion() + 1);
        users.save(user);
        jdbcTemplate.update("DELETE FROM password_reset_tokens WHERE user_id=?", userId);
    }

    @Transactional
    public void requestPasswordReset(AuthDtos.ForgotPasswordRequest request) {
        String email = request.email().trim().toLowerCase(Locale.ROOT);
        users.findByEmail(email).ifPresent(user -> {
            String token = UUID.randomUUID().toString().replace("-", "")
                    + UUID.randomUUID().toString().replace("-", "");
            String tokenHash = sha256(token);
            jdbcTemplate.update("DELETE FROM password_reset_tokens WHERE user_id=?", user.getId());
            jdbcTemplate.update(
                    "INSERT INTO password_reset_tokens(user_id, token_hash, expires_at, created_at) "
                            + "VALUES (?, ?, now() + interval '30 minutes', now())",
                    user.getId(), tokenHash);
        });
    }

    @Transactional
    public void resetPassword(AuthDtos.ResetPasswordRequest request) {
        if (!request.token().matches("[a-fA-F0-9]{64}")) {
            throw new ResponseStatusException(HttpStatus.UNPROCESSABLE_ENTITY, "reset_token_invalid");
        }
        Long userId = jdbcTemplate.query(
                "SELECT user_id FROM password_reset_tokens "
                        + "WHERE token_hash=? AND used_at IS NULL AND expires_at > now()",
                resultSet -> resultSet.next() ? resultSet.getLong(1) : null,
                sha256(request.token()));
        if (userId == null) {
            throw new ResponseStatusException(HttpStatus.UNPROCESSABLE_ENTITY, "reset_token_invalid");
        }
        User user = users.findById(userId).orElseThrow(
                () -> new ResponseStatusException(HttpStatus.UNPROCESSABLE_ENTITY, "reset_token_invalid"));
        user.setPasswordHash(passwordEncoder.encode(request.password()));
        user.setSessionVersion(user.getSessionVersion() + 1);
        users.save(user);
        jdbcTemplate.update(
                "UPDATE password_reset_tokens SET used_at=now() "
                        + "WHERE user_id=? AND used_at IS NULL",
                userId);
    }

    public void logout(HttpServletRequest request) {
        HttpSession session = request.getSession(false);
        if (session != null) session.invalidate();
        SecurityContextHolder.clearContext();
    }

    private AuthDtos.AuthUser publicUser(User user) {
        Organisation organisation = user.getOrganisation();
        AuthDtos.OrganisationSummary organisationSummary = organisation == null ? null :
                new AuthDtos.OrganisationSummary(
                        organisation.getId(), organisation.getName(),
                        organisation.getSlug(), organisation.getStatus());
        List<String> roles = organisation == null
                ? jdbcTemplate.queryForList(
                        "SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id=r.id "
                                + "WHERE ur.user_id=? AND r.organisation_id IS NULL",
                        String.class, user.getId())
                : jdbcTemplate.queryForList(
                        "SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id=r.id "
                                + "WHERE ur.user_id=? AND r.organisation_id=?",
                        String.class, user.getId(), organisation.getId());
        List<String> permissions = jdbcTemplate.queryForList(
                "SELECT DISTINCT p.code FROM permissions p "
                        + "JOIN role_permissions rp ON rp.permission_id=p.id "
                        + "JOIN user_roles ur ON ur.role_id=rp.role_id "
                        + "WHERE ur.user_id=?",
                String.class, user.getId());
        return new AuthDtos.AuthUser(
                user.getId(), user.getEmail(), user.getFullName(), user.getStatus(),
                organisationSummary, roles, permissions);
    }

    private void enforceThrottle(String email, HttpServletRequest request) {
        String bucket = throttleBucket(email, request);
        String result = jdbcTemplate.query(
                "SELECT failed_attempts, locked_until FROM login_attempts WHERE bucket_hash=?",
                resultSet -> resultSet.next()
                        ? resultSet.getInt("failed_attempts") + "|" +
                        (resultSet.getTimestamp("locked_until") == null ? "" :
                                resultSet.getTimestamp("locked_until").toInstant().toString())
                        : null,
                bucket);
        if (result != null && !result.endsWith("|")) {
            String[] parts = result.split("\\|", 2);
            if (!parts[1].isBlank() &&
                    OffsetDateTime.parse(parts[1]).isAfter(OffsetDateTime.now())) {
                throw new LockedException("login_throttled");
            }
        }
    }

    private void recordFailure(String email, HttpServletRequest request) {
        String bucket = throttleBucket(email, request);
        Integer failed = jdbcTemplate.queryForObject(
                "INSERT INTO login_attempts(bucket_hash, failed_attempts, window_started_at, locked_until, updated_at) "
                        + "VALUES (?, 1, now(), NULL, now()) "
                        + "ON CONFLICT (bucket_hash) DO UPDATE SET "
                        + "failed_attempts = CASE "
                        + "WHEN login_attempts.updated_at < now() - interval '15 minutes' THEN 1 "
                        + "WHEN login_attempts.locked_until IS NOT NULL AND login_attempts.locked_until > now() "
                        + "THEN login_attempts.failed_attempts ELSE login_attempts.failed_attempts + 1 END, "
                        + "locked_until = CASE "
                        + "WHEN login_attempts.updated_at < now() - interval '15 minutes' THEN NULL "
                        + "WHEN login_attempts.failed_attempts + 1 >= 5 THEN now() + interval '15 minutes' "
                        + "ELSE login_attempts.locked_until END, "
                        + "updated_at=now() "
                        + "RETURNING failed_attempts",
                Integer.class, bucket);
        if (failed != null && failed >= 5) throw new LockedException("login_throttled");
    }

    private void clearThrottle(String email, HttpServletRequest request) {
        jdbcTemplate.update("DELETE FROM login_attempts WHERE bucket_hash=?", throttleBucket(email, request));
    }

    private String throttleBucket(String email, HttpServletRequest request) {
        String ip = request.getRemoteAddr();
        return hmacSha256(ipHashSecret, "email-ip|" + email + "|" + ip);
    }

    private void assignOwnerPermissions(Long roleId) {
        for (String code : List.of(
                "finance.read", "finance.write", "operations.read", "operations.write",
                "risks.read", "reports.read", "decisions.manage", "connect.manage",
                "documents.read", "documents.upload", "integrations.manage",
                "users.manage", "data.import")) {
            jdbcTemplate.update(
                    "INSERT INTO permissions(code, description, created_at) "
                            + "VALUES (?, ?, now()) "
                            + "ON CONFLICT (code) DO UPDATE SET description=EXCLUDED.description",
                    code, "VANTIQ application permission");
            Long permissionId = jdbcTemplate.queryForObject(
                    "SELECT id FROM permissions WHERE code=?", Long.class, code);
            jdbcTemplate.update(
                    "INSERT INTO role_permissions(role_id, permission_id, created_at) "
                            + "VALUES (?, ?, now()) ON CONFLICT DO NOTHING",
                    roleId, permissionId);
        }
    }

    private static String sha256(String value) {
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            return HexFormat.of().formatHex(digest.digest(value.getBytes(StandardCharsets.UTF_8)));
        } catch (Exception exception) {
            throw new IllegalStateException("Unable to initialize SHA-256.", exception);
        }
    }

    private static String hmacSha256(String secret, String value) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
            return HexFormat.of().formatHex(mac.doFinal(value.getBytes(StandardCharsets.UTF_8)));
        } catch (Exception exception) {
            throw new IllegalStateException("Unable to initialize login throttle secret.", exception);
        }
    }

    public record AuthPrincipal(Long userId, Long organisationId) {}
}
