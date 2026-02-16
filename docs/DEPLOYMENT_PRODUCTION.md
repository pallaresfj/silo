# Despliegue a Produccion - SILO

Este documento define el flujo oficial de despliegue para SILO en Hostinger con Laravel 12 y PHP 8.4.

## Alcance

- Primer despliegue
- Actualizaciones futuras
- Verificacion operativa

## Parametros Operativos

- Rama de despliegue: `main`
- Ruta del proyecto en servidor: `~/domains/iedagropivijay.edu.co/public_html/silo`
- Document Root del sitio: `.../public_html/silo/public`
- PHP CLI: `/opt/alt/php84/usr/bin/php`
- Composer: `/usr/local/bin/composer2`
- Scheduler (cron): `* * * * * cd /home/<usuario>/domains/iedagropivijay.edu.co/public_html/silo && /opt/alt/php84/usr/bin/php artisan schedule:run >> /dev/null 2>&1`
- Frontend assets: se compilan en local (`npm run build`) y se sincronizan a `public/build` en servidor

## Checklist de Entorno

Antes de abrir produccion, confirmar en `.env`:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://<dominio-final>`
- `DB_*`
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`
- `GOOGLE_DRIVE_*` (service account + folder + templates)
- `MAIL_*` (SMTP real)
- `DRIVE_SYNC_ENABLED=true`
- `DRIVE_SYNC_NOTIFY=true`
- `DRIVE_SYNC_NOTIFY_ROLES=administrador,rector`

## Flujo 1: Primer Despliegue

### 1) Preparar release local

```bash
cd /Users/pallaresfj/Herd/silo
npm ci
npm run build
git add .
git commit -m "release: deploy production"
git push origin main
```

### 2) Instalar en servidor

```bash
ssh -p 65002 <usuario>@167.88.34.121
cd ~/domains/iedagropivijay.edu.co/public_html
git clone <URL_DEL_REPO> silo
cd silo
```

### 3) Dependencias PHP

```bash
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 install --no-dev --prefer-dist --optimize-autoloader
```

### 4) Crear y configurar `.env`

```bash
cp .env.example .env
```

### 5) Generar APP key (solo primera vez)

```bash
/opt/alt/php84/usr/bin/php artisan key:generate --force
```

### 6) Migraciones y seeders

```bash
/opt/alt/php84/usr/bin/php artisan migrate --force
/opt/alt/php84/usr/bin/php artisan db:seed --force
```

No usar `migrate:fresh --seed` en produccion.

### 7) Enlace de storage (avatares)

```bash
/opt/alt/php84/usr/bin/php artisan storage:link
```

### 8) Subir assets compilados

```bash
rsync -avz --delete -e "ssh -p 65002" /Users/pallaresfj/Herd/silo/public/build/ <usuario>@167.88.34.121:~/domains/iedagropivijay.edu.co/public_html/silo/public/build/
```

### 9) Cache y optimizacion

```bash
/opt/alt/php84/usr/bin/php artisan optimize:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan view:cache
```

## Flujo 2: Actualizaciones Futuras

### 1) Preparar release local

```bash
cd /Users/pallaresfj/Herd/silo
npm ci
npm run build
git push origin main
```

### 2) Actualizar en servidor

```bash
ssh -p 65002 <usuario>@167.88.34.121
cd ~/domains/iedagropivijay.edu.co/public_html/silo
git pull origin main
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 install --no-dev --prefer-dist --optimize-autoloader
/opt/alt/php84/usr/bin/php artisan migrate --force
```

### 3) Sincronizar assets compilados

```bash
rsync -avz --delete -e "ssh -p 65002" /Users/pallaresfj/Herd/silo/public/build/ <usuario>@167.88.34.121:~/domains/iedagropivijay.edu.co/public_html/silo/public/build/
```

### 4) Refrescar cache

```bash
/opt/alt/php84/usr/bin/php artisan optimize:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan view:cache
```

## Cron obligatorio

Crear este Cron Job en cPanel:

```cron
* * * * * cd /home/<usuario>/domains/iedagropivijay.edu.co/public_html/silo && /opt/alt/php84/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

El scheduler ejecuta cada hora `drive:sync-unclassified` (definido en `routes/console.php`).

## Verificacion Post-Deploy

```bash
/opt/alt/php84/usr/bin/php artisan about
/opt/alt/php84/usr/bin/php artisan migrate:status
/opt/alt/php84/usr/bin/php artisan test:google-drive --direct
/opt/alt/php84/usr/bin/php artisan drive:sync-unclassified --bootstrap
```

Probar adicionalmente:

- Login Google en `/admin`
- Cambio de avatar y acceso por `/storage/...`
- Correo de notificacion de documentos `Importado_Sin_Clasificar`

## Seguridad

- Ruta de diagnostico web `/test-gdrive` removida del proyecto.
- Para diagnostico de Drive usar solo comando CLI: `php artisan test:google-drive --direct`.
- No guardar credenciales reales en Git.
