<?php

declare(strict_types=1);

namespace Storm\Telemetry\Schema;

use Doctrine\DBAL\Connection;
use Storm\Support\Dbal\SchemaCatalog;

/**
 * One module's schema as the conformance surfaces read it between two installs: its declared
 * catalog, the tables it owns on one connection, and the installer whose re-run converges an
 * additive drift.
 *
 * A module is read on the connection it names, or on the one the surface runs against when it
 * names none; a split read-model store hands its `projections` table over as a target of its own.
 * A module whose first table is absent is not installed, and reads as such rather than as drift.
 */
final readonly class SchemaConformanceTarget
{
    /**
     * @param  list<string>  $tables  the tables the module owns on this connection, every one a key of the catalog
     */
    public function __construct(
        public string $module,
        public SchemaCatalog $catalog,
        public array $tables,
        public string $installer,
        public ?Connection $connection = null,
    ) {}
}
