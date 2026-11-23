package io.vantiq.security;

import org.springframework.security.crypto.bcrypt.BCryptPasswordEncoder;
import org.springframework.security.crypto.password.PasswordEncoder;

import java.util.regex.Pattern;

public final class LegacyPhpPasswordEncoder implements PasswordEncoder {
    private static final Pattern PHP_BCRYPT = Pattern.compile("^\\$2y\\$\\d{2}\\$[./A-Za-z0-9]{53}$");
    private final BCryptPasswordEncoder bcrypt = new BCryptPasswordEncoder();

    @Override
    public String encode(CharSequence rawPassword) {
        return bcrypt.encode(rawPassword);
    }

    @Override
    public boolean matches(CharSequence rawPassword, String encodedPassword) {
        if (encodedPassword == null) return false;
        if (PHP_BCRYPT.matcher(encodedPassword).matches()) {
            return bcrypt.matches(rawPassword, "$2a$" + encodedPassword.substring(4));
        }
        return bcrypt.matches(rawPassword, encodedPassword);
    }

    @Override
    public boolean upgradeEncoding(String encodedPassword) {
        if (encodedPassword == null) return false;
        if (PHP_BCRYPT.matcher(encodedPassword).matches()) return true;
        return bcrypt.upgradeEncoding(encodedPassword);
    }
}
