package io.vantiq.auth;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.GeneratedValue;
import jakarta.persistence.GenerationType;
import jakarta.persistence.Id;
import jakarta.persistence.Table;

@Entity
@Table(name = "roles")
public class Role {
    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    @Column(name = "organisation_id")
    private Long organisationId;

    @Column(nullable = false, length = 120)
    private String name;

    @Column(length = 255)
    private String description;

    @Column(name = "is_system", nullable = false)
    private Boolean system = false;

    protected Role() {}

    public Long getId() { return id; }
    public Long getOrganisationId() { return organisationId; }
    public String getName() { return name; }
    public String getDescription() { return description; }
    public Boolean getSystem() { return system; }
}
