package io.vantiq.security;

import org.junit.jupiter.api.Test;
import org.springframework.security.crypto.bcrypt.BCryptPasswordEncoder;

import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

class LegacyPhpPasswordEncoderTest {
    private final LegacyPhpPasswordEncoder encoder = new LegacyPhpPasswordEncoder();

    @Test
    void verifiesPhpBcryptPrefixWithoutResettingPasswords() {
        String password = "test-password";
        String bcryptHash = new BCryptPasswordEncoder().encode(password);
        String phpHash = "$2y$" + bcryptHash.substring(4);

        assertTrue(encoder.matches(password, phpHash));
        assertFalse(encoder.matches("not-the-password", phpHash));
        assertTrue(encoder.upgradeEncoding(phpHash));
    }
}
