# Documento Técnico: Sistema de Gestión Documental IED (Silo)

**Versión:** 1.0.0 (Febrero 2026)

**Proyecto:** Gestión Documental "Headless" con Google Drive

**Institución:** IED Agropecuaria José María Herrera

**Dominio:** `iedagropivijay.edu.co`

---

## 1. Stack Tecnológico & Entorno

Este proyecto debe ser inicializado en **Laravel Herd** bajo las siguientes especificaciones:

- **Framework:** Laravel 12 (Última versión estable).
    
- **Admin UI:** Filament 5.x.
    
- **Frontend Interactivo:** Livewire (Nativo en Filament 5).
    
- **Base de Datos:** MySQL 8.0+.
    
- **Filesystem Cloud:** Google Drive API v3 (vía Service Account).
    
- **Driver:** `spatie/flysystem-google-drive` (Compatible con Laravel 12).
    
- **Entorno:** Antigravity (AI-Assisted IDE).
    

---

## 2. Arquitectura de Datos (Schema Design)

El sistema no guarda binarios en la base de datos, solo referencias (IDs de Drive) y metadatos ricos.

### A. Migraciones Principales

**Tabla: `document_categories`** (Tipos de documento)

- `id` (PK)
    
- `name` (String: Resolución, Acta, Circular, Proyecto, Informe Financiero)
    
- `slug` (Unique)
    
- `is_system` (Boolean: Para evitar borrar categorías base)
    

**Tabla: `entities`** (Terceros: Remitentes/Destinatarios)

- `id` (PK)
    
- `name` (String: MEN, Secretaría de Educación, Sindicato, Rectoría)
    
- `type` (Enum: 'Interna', 'Externa')
    

**Tabla: `documents`** (El corazón del sistema)

- `id` (UUID - Recomendado para sistemas de archivos grandes)
    
- `gdrive_id` (String: El ID real en Google Drive, indexado)
    
- `gdrive_url` (String: WebViewLink para acceso directo)
    
- `file_name` (String: Nombre original del archivo)
    
- `title` (String: Nombre amigable para búsqueda)
    
- `year` (Integer: 2025, 2026... Indispensable para filtrado escolar)
    
- `category_id` (FK -> `document_categories`)
    
- `entity_id` (FK -> `entities` - Origen/Destino)
    
- `status` (Enum: 'Borrador', 'Publicado', 'Archivado', 'Pendiente_OCR')
    
- `metadata` (JSON: Para tags dinámicos o datos extra sin migrar tabla)
    
- `timestamps`
    

---

## 3. Integración Google Drive (Service Layer)

### Configuración del Filesystem (`config/filesystems.php`)

Se debe configurar un disco personalizado `google` que utilice las credenciales del Service Account (`storage/credentials/service-account.json`).

PHP

```
// config/filesystems.php (Laravel 12 Style)
'google' => [
    'driver' => 'google',
    'clientId' => env('GOOGLE_DRIVE_CLIENT_ID'),
    'clientSecret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
    'refreshToken' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
    'folder' => env('GOOGLE_DRIVE_FOLDER_ID'), // ID de la carpeta Raíz Institucional
],
```

### Lógica de Organización Automática

En el modelo `Document`, usar un _Observer_ o el método `booted` para que, al subir un archivo desde Filament, este se ubique automáticamente en la carpeta correcta de Drive:

> **Regla de Negocio:** Ruta en Drive: `/SGI-Doc/{Año}/{Categoría}/{Nombre_Archivo}` _Ejemplo:_ `/SGI-Doc/2026/Resoluciones/Res_001_Nombramiento.pdf`

---

## 4. Diseño del Panel Filament 5

Se requieren dos recursos principales y un widget de dashboard.

### A. Resource: `DocumentResource`

**Formulario (Create/Edit):**

1. **Sección Archivo:**
    
    - `FileUpload::make('attachment')`:
        
        - Disk: `google`.
            
        - Directory: dinámico basado en `$get('year')`.
            
        - Visibility: `private`.
            
        - _Feature:_ `preserveFilenames()`.
            
2. **Sección Metadatos (Side Panel):**
    
    - `Select::make('category_id')`: Relationship.
        
    - `Select::make('entity_id')`: Relationship (Searchable).
        
    - `TextInput::make('year')`: Default `now()->year`.
        
    - `TagsInput::make('tags')`: Guardado en columna JSON `metadata`.
        

**Tabla (List/Index):**

- **Columnas:**
    
    - `TextColumn::make('title')`: Searchable (Búsqueda global).
        
    - `BadgeColumn::make('category.name')`: Colors por tipo.
        
    - `TextColumn::make('year')`: Sortable.
        
    - `IconColumn::make('gdrive_url')`: Link externo "Ver en Drive".
        
- **Filtros (Filters):**
    
    - `SelectFilter::make('year')`: 2026, 2025...
        
    - `SelectFilter::make('category')`.
        
- **Acciones (Actions):**
    
    - `Action::make('preview')`: Modal con iframe del PDF (si Drive lo permite).
        
    - `Action::make('download')`.
        

### B. Funcionalidad "Legacy Import" (Para 2015-2025)

No se migrarán archivos manualmente. Se creará un **Filament Custom Page** o un **Command** llamado `ImportDriveFolder`.

- **Input:** ID de una carpeta de Drive existente (ej: Carpeta "2024").
    
- **Proceso:**
    
    1. Listar archivos recursivamente de esa carpeta via API.
        
    2. Verificar si el `gdrive_id` ya existe en MySQL.
        
    3. Si no existe -> Crear registro en `documents`.
        
        - `title`: Nombre del archivo.
            
        - `year`: Extraído de la fecha de creación del archivo.
            
        - `status`: 'Importado_Sin_Clasificar'.

---

## 5. Operacion de sincronizacion Drive

- Comando programado (cron interno Laravel Scheduler):
  - `php artisan drive:sync-unclassified`
  - Frecuencia: cada hora.
  - Proposito: detectar archivos creados fuera de la app e importarlos para clasificacion.
- Bootstrap manual inicial (cuando se necesite reconstruir estado):
  - `php artisan drive:sync-unclassified --bootstrap`
- Comando de limpieza de huérfanos:
  - `php artisan drive:cleanup-orphans`
  - Uso: solo manual para incidentes o soporte.
  - Regla: no debe programarse en cron.
            

---
