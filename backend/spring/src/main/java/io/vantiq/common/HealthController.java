package io.vantiq.common;

import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RestController;

import javax.sql.DataSource;
import java.sql.Connection;
import java.sql.SQLException;
import java.util.Map;

@RestController
public class HealthController {
    private final DataSource dataSource;

    public HealthController(DataSource dataSource) {
        this.dataSource = dataSource;
    }

    @GetMapping("/api/health")
    public ResponseEntity<ApiResponse<Map<String, Object>>> health() {
        boolean connected;
        try (Connection connection = dataSource.getConnection()) {
            connected = connection.isValid(2);
        } catch (SQLException exception) {
            connected = false;
        }

        Map<String, Object> status = Map.of(
                "status", connected ? "ok" : "degraded",
                "java_version", Runtime.version().feature(),
                "database", Map.of("connected", connected)
        );
        return ResponseEntity.status(connected ? HttpStatus.OK : HttpStatus.SERVICE_UNAVAILABLE)
                .body(ApiResponse.ok(status));
    }
}
