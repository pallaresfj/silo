<div class="w-full" style="min-height: 600px;">
    @if ($url)
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