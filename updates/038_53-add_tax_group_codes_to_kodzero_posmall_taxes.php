<?php

declare(strict_types=1);

use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Compatibility migration kept in sequence only. The normalized schema in
        // 038_54 stores child tax group codes in kodzero_posmall_tax_group_codes.
    }

    public function down(): void
    {
        // No-op.
    }
};
