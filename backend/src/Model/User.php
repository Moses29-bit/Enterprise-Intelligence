<?php
declare(strict_types=1);

namespace Vantiq\Model;

final class User
{
    private function __construct(
        public readonly int $id,
        public readonly ?int $organisationId,
        public readonly string $email,
        public readonly string $name,
        public readonly string $status
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['organisation_id'] === null ? null : (int) $row['organisation_id'],
            (string) $row['email'],
            (string) $row['full_name'],
            (string) $row['status']
        );
    }

    public function toPublicArray(?Organisation $organisation, array $roles, array $permissions): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'status' => $this->status,
            'organisation' => $organisation?->toArray(),
            'roles' => $roles,
            'permissions' => $permissions
        ];
    }
}
