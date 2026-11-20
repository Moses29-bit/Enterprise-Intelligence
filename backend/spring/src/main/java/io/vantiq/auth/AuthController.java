package io.vantiq.auth;

import io.vantiq.common.ApiResponse;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpSession;
import jakarta.validation.Valid;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.security.web.csrf.CsrfToken;
import org.springframework.security.web.csrf.CsrfTokenRepository;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.Map;

@RestController
@RequestMapping("/api/auth")
public class AuthController {
    private final AuthService authService;
    private final CsrfTokenRepository csrfTokenRepository;

    public AuthController(AuthService authService, CsrfTokenRepository csrfTokenRepository) {
        this.authService = authService;
        this.csrfTokenRepository = csrfTokenRepository;
    }

    @GetMapping("/csrf")
    public ResponseEntity<ApiResponse<Map<String, String>>> csrf(HttpServletRequest request) {
        HttpSession session = request.getSession(true);
        CsrfToken token = csrfTokenRepository.generateToken(request);
        csrfTokenRepository.saveToken(token, request, null);
        return ResponseEntity.ok(ApiResponse.ok(Map.of("csrfToken", token.getToken())));
    }

    @PostMapping("/login")
    public ResponseEntity<ApiResponse<Map<String, Object>>> login(
            @Valid @RequestBody AuthDtos.LoginRequest request,
            HttpServletRequest httpRequest
    ) {
        AuthDtos.AuthUser user = authService.login(request, httpRequest);
        CsrfToken token = csrfTokenRepository.loadToken(httpRequest);
        if (token == null) token = csrfTokenRepository.generateToken(httpRequest);
        csrfTokenRepository.saveToken(token, httpRequest, null);
        String csrfToken = token.getToken();
        return ResponseEntity.ok(ApiResponse.ok(Map.of(
                "user", user,
                "csrfToken", csrfToken)));
    }

    @PostMapping("/register")
    public ResponseEntity<ApiResponse<Map<String, Object>>> register(
            @Valid @RequestBody AuthDtos.RegisterRequest request
    ) {
        AuthDtos.AuthUser user = authService.register(request);
        return ResponseEntity.status(HttpStatus.CREATED).body(ApiResponse.ok(Map.of("user", user)));
    }

    @GetMapping("/me")
    public ResponseEntity<ApiResponse<Map<String, AuthDtos.AuthUser>>> me(HttpServletRequest request) {
        return ResponseEntity.ok(ApiResponse.ok(Map.of("user", authService.currentUser(request))));
    }

    @PostMapping("/logout")
    public ResponseEntity<ApiResponse<Map<String, Boolean>>> logout(HttpServletRequest request) {
        authService.logout(request);
        return ResponseEntity.ok(ApiResponse.ok(Map.of("loggedOut", true)));
    }

    @PostMapping("/change-password")
    public ResponseEntity<ApiResponse<Map<String, Boolean>>> changePassword(
            @Valid @RequestBody AuthDtos.ChangePasswordRequest request,
            HttpServletRequest httpRequest
    ) {
        Object userId = httpRequest.getSession(false).getAttribute("VANTIQ_USER_ID");
        if (!(userId instanceof Number number)) {
            throw new org.springframework.web.server.ResponseStatusException(
                    HttpStatus.UNAUTHORIZED, "Authentication is required.");
        }
        authService.changePassword(number.longValue(), request);
        return ResponseEntity.ok(ApiResponse.ok(Map.of("changed", true)));
    }

    @PostMapping("/forgot-password")
    public ResponseEntity<ApiResponse<Map<String, String>>> forgotPassword(
            @Valid @RequestBody AuthDtos.ForgotPasswordRequest request
    ) {
        authService.requestPasswordReset(request);
        return ResponseEntity.ok(ApiResponse.ok(Map.of(
                "message", "If an active account exists for that email, password-reset instructions are queued.")));
    }

    @PostMapping("/reset-password")
    public ResponseEntity<ApiResponse<Map<String, Boolean>>> resetPassword(
            @Valid @RequestBody AuthDtos.ResetPasswordRequest request
    ) {
        authService.resetPassword(request);
        return ResponseEntity.ok(ApiResponse.ok(Map.of("reset", true)));
    }
}
