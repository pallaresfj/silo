<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriveUnclassifiedDetected extends Notification
{
    use Queueable;

    /**
     * @param  list<array{title: string, path: string, status: string, url: string|null}>  $topItems
     */
    public function __construct(
        protected int $importedTotal,
        protected int $importedUnclassified,
        protected array $topItems,
        protected string $reviewUrl,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject('SILO: nuevos documentos sin clasificar en Drive')
            ->greeting('Hola,')
            ->line('Se detectaron nuevos archivos creados directamente en Google Drive.')
            ->line("Importados en esta corrida: {$this->importedTotal}")
            ->line("Pendientes de clasificacion: {$this->importedUnclassified}");

        foreach ($this->topItems as $item) {
            $title = trim((string) ($item['title'] ?? 'Documento'));
            $path = trim((string) ($item['path'] ?? '/'));
            $url = trim((string) ($item['url'] ?? ''));

            $line = "- {$title} ({$path})";
            if ($url !== '') {
                $line .= " - {$url}";
            }

            $mail->line($line);
        }

        return $mail
            ->action('Revisar pendientes', $this->reviewUrl)
            ->line('Este correo se envia solo cuando hay novedades sin clasificar.');
    }
}
