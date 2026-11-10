<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indeks untuk query yang sebelumnya memindai seluruh tabel:
     * daftar tiket per divisi/pemilik, filter tanggal dashboard, cron SLA,
     * hitung beban PIC, riwayat per tiket, dan pencarian NIK saat sinkron
     * Talenta. Sekaligus mengunci keanggotaan divisi agar tidak bisa dobel.
     *
     * @var array<string, array<string, list<string>>>
     */
    private array $indexes = [
        'tickets' => [
            'tickets_unit_status_created_index' => ['unit_id', 'ticket_statuses_id', 'created_at'],
            'tickets_owner_created_index' => ['owner_id', 'created_at'],
            'tickets_responsible_status_index' => ['responsible_id', 'ticket_statuses_id'],
            'tickets_created_at_index' => ['created_at'],
            'tickets_sla_due_at_index' => ['sla_due_at'],
        ],
        'ticket_histories' => [
            'ticket_histories_ticket_created_index' => ['ticket_id', 'created_at'],
        ],
        'users' => [
            'users_identity_index' => ['identity'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name) || ! Schema::hasColumns($table, $columns)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
                    $blueprint->index($columns, $name);
                });
            }
        }

        if (Schema::hasTable('user_entities')
            && ! Schema::hasIndex('user_entities', 'user_entities_user_entity_unique')) {
            $this->removeDuplicateMemberships();

            Schema::table('user_entities', function (Blueprint $table): void {
                $table->unique(['user_id', 'entity_type', 'entity_id'], 'user_entities_user_entity_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_entities')
            && Schema::hasIndex('user_entities', 'user_entities_user_entity_unique')) {
            Schema::table('user_entities', function (Blueprint $table): void {
                $table->dropUnique('user_entities_user_entity_unique');
            });
        }

        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($indexes) as $name) {
                if (! Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }

    /**
     * Sisakan baris tertua untuk setiap kombinasi user + entitas.
     */
    private function removeDuplicateMemberships(): void
    {
        $keepIds = DB::table('user_entities')
            ->selectRaw('min(id) as id')
            ->groupBy('user_id', 'entity_type', 'entity_id')
            ->pluck('id');

        DB::table('user_entities')
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
};
