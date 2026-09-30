<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->text('secret')->nullable()->change();
        });

        Schema::table('whats_app_conversation_logs', function (Blueprint $table) {
            $table->dropIndex(['phone_number']);
            $table->text('phone_number')->nullable()->change();
            $table->text('metadata')->nullable()->change();
        });

        $this->encryptColumn('webhooks', 'secret');
        $this->encryptColumn('whats_app_conversation_logs', 'phone_number');
        $this->encryptColumn('whats_app_conversation_logs', 'message');
        $this->encryptColumn('whats_app_conversation_logs', 'reply');
        $this->encryptColumn('whats_app_conversation_logs', 'error_message');
        $this->encryptColumn('whats_app_conversation_logs', 'metadata', true);
    }

    public function down(): void
    {
        $this->decryptColumn('webhooks', 'secret');
        $this->decryptColumn('whats_app_conversation_logs', 'phone_number');
        $this->decryptColumn('whats_app_conversation_logs', 'message');
        $this->decryptColumn('whats_app_conversation_logs', 'reply');
        $this->decryptColumn('whats_app_conversation_logs', 'error_message');
        $this->decryptColumn('whats_app_conversation_logs', 'metadata');

        Schema::table('whats_app_conversation_logs', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->change();
            $table->json('metadata')->nullable()->change();
            $table->index('phone_number');
        });

        Schema::table('webhooks', function (Blueprint $table) {
            $table->string('secret')->nullable()->change();
        });
    }

    private function encryptColumn(string $table, string $column, bool $json = false): void
    {
        DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(100, function ($rows) use ($table, $column, $json) {
            foreach ($rows as $row) {
                $value = (string) $row->{$column};

                try {
                    Crypt::decryptString($value);

                    continue;
                } catch (Throwable) {
                    // Existing plaintext value; encrypt it below.
                }

                if ($json) {
                    $decoded = json_decode($value, true);
                    $value = json_encode(is_array($decoded) ? $decoded : [], JSON_THROW_ON_ERROR);
                }

                DB::table($table)->where('id', $row->id)->update([$column => Crypt::encryptString($value)]);
            }
        });
    }

    private function decryptColumn(string $table, string $column): void
    {
        DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(100, function ($rows) use ($table, $column) {
            foreach ($rows as $row) {
                try {
                    $value = Crypt::decryptString((string) $row->{$column});
                } catch (Throwable) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([$column => $value]);
            }
        });
    }
};
