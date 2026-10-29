<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\Support;

/**
 * A driver's configuration schema, so connection forms are generated rather than
 * hand-written per driver (spec §5).
 */
final readonly class DriverConfigurationSchema
{
    /**
     * @param  list<ConfigurationField>  $fields
     */
    public function __construct(public array $fields) {}

    /**
     * @return list<ConfigurationField>
     */
    public function secretFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (ConfigurationField $field): bool => $field->secret,
        ));
    }

    /**
     * Whether a connection of this driver may hold no secret at all: no field is
     * both secret and required (an unauthenticated probe, an in-process driver).
     */
    public function credentialOptional(): bool
    {
        foreach ($this->secretFields() as $field) {
            if ($field->required) {
                return false;
            }
        }

        return true;
    }

    public function field(string $name): ?ConfigurationField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }
}
