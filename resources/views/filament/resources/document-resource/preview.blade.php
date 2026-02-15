<div class="w-full" style="min-height: 600px;">
    @if ($url)
        <div class="mb-3">
            <a
                href="{{ $url }}"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
            >
                Abrir en nueva pestaña
            </a>
        </div>

        <iframe src="{{ $url }}" class="w-full rounded-lg border border-gray-200 dark:border-gray-700" style="height: 75vh;"
            allow="autoplay" loading="lazy"></iframe>
    @else
        <div class="flex items-center justify-center py-12 text-gray-500 dark:text-gray-400">
            <div class="text-center">
                <x-heroicon-o-document class="mx-auto h-12 w-12 text-gray-400" />
                <p class="mt-2 text-sm">No hay vista previa disponible para este documento.</p>
            </div>
        </div>
    @endif
</div>
