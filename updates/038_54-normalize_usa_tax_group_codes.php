<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use KodZero\POSMall\Models\Tax;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kodzero_posmall_taxes', function (Blueprint $table) {
            if (!Schema::hasColumn($table->getTable(), 'tax_main_group')) {
                $table->string('tax_main_group', 30)->nullable()->index();
            }

            if (!Schema::hasColumn($table->getTable(), 'tax_main_group_name')) {
                $table->string('tax_main_group_name')->nullable();
            }
        });

        if (!Schema::hasTable('kodzero_posmall_tax_group_codes')) {
            Schema::create('kodzero_posmall_tax_group_codes', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('tax_id')->index();
                $table->string('tax_group_code', 80)->index();
                $table->string('tax_group_name')->nullable();
                $table->text('tax_group_description')->nullable();
                $table->timestamps();
                $table->unique(['tax_id', 'tax_group_code']);
            });
        }

        $this->backfillChildCodes();
        $this->dropLegacyCodesColumn();
    }

    public function down(): void
    {
        Schema::table('kodzero_posmall_taxes', function (Blueprint $table) {
            if (!Schema::hasColumn($table->getTable(), 'tax_group_codes')) {
                $table->text('tax_group_codes')->nullable();
            }
        });

        if (Schema::hasTable('kodzero_posmall_tax_group_codes')) {
            DB::table('kodzero_posmall_tax_group_codes')
                ->orderBy('tax_id')
                ->get()
                ->groupBy('tax_id')
                ->each(function ($rows, $taxId) {
                    DB::table('kodzero_posmall_taxes')
                        ->where('id', $taxId)
                        ->update([
                            'tax_group_codes' => json_encode($rows->pluck('tax_group_code')->unique()->values()->all()),
                        ]);
                });
        }

        Schema::dropIfExists('kodzero_posmall_tax_group_codes');

        foreach (['tax_main_group', 'tax_main_group_name'] as $column) {
            if (!Schema::hasColumn('kodzero_posmall_taxes', $column)) {
                continue;
            }

            Schema::table('kodzero_posmall_taxes', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }

    protected function backfillChildCodes(): void
    {
        DB::table('kodzero_posmall_taxes')
            ->orderBy('id')
            ->get()
            ->each(function ($tax) {
                $codes = $this->codesFor($tax);

                foreach ($codes as $code) {
                    DB::table('kodzero_posmall_tax_group_codes')->updateOrInsert(
                        [
                            'tax_id' => $tax->id,
                            'tax_group_code' => $code,
                        ],
                        [
                            'tax_group_name' => $code === $tax->tax_group_code ? $tax->tax_group_name : null,
                            'tax_group_description' => $code === $tax->tax_group_code ? $tax->tax_group_description : null,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }

                $mainGroup = Tax::taxMainGroupForCodes($codes);

                DB::table('kodzero_posmall_taxes')
                    ->where('id', $tax->id)
                    ->update([
                        'tax_main_group' => $mainGroup,
                        'tax_main_group_name' => Tax::taxMainGroupOptions()[$mainGroup] ?? 'General',
                    ]);
            });
    }

    protected function codesFor($tax): array
    {
        $codes = [$tax->tax_group_code];

        if (Schema::hasColumn('kodzero_posmall_taxes', 'tax_group_codes')) {
            $codes = array_merge($codes, $this->normalizeCodes($tax->tax_group_codes ?? null));
        }

        return collect($codes)
            ->map(fn ($code) => strtoupper(trim((string)$code)))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function normalizeCodes($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : preg_split('/\s*[,;.]\s*/', $value);
        }

        if (!is_array($value)) {
            return $value ? [$value] : [];
        }

        return $value;
    }

    protected function dropLegacyCodesColumn(): void
    {
        if (!Schema::hasColumn('kodzero_posmall_taxes', 'tax_group_codes')) {
            return;
        }

        Schema::table('kodzero_posmall_taxes', function (Blueprint $table) {
            $table->dropColumn('tax_group_codes');
        });
    }
};
