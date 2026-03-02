<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('gdrive_id')->nullable()->index();
            $table->string('gdrive_url')->nullable();
            $table->string('file_name');
            $table->string('title');
            $table->unsignedSmallInteger('year')->default(now()->year)->index();
            $table->string('storage_scope', 32)->default('yearly')->index();
            $table->foreignUuid('category_id')
                ->constrained('document_categories')
                ->cascadeOnDelete();
            $table->foreignUuid('entity_id')
                ->nullable()
                ->constrained('entities')
                ->nullOnDelete();
            $table->enum('status', [
                'Borrador',
                'Publicado',
                'Archivado',
                'Pendiente_OCR',
                'Importado_Sin_Clasificar',
            ])->default('Borrador');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
