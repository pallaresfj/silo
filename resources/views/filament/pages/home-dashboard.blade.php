<x-filament-panels::page>
    <div class="silo-dashboard">
        <section class="silo-dashboard-top">
            <form
                action="{{ $links['documentsIndex'] }}"
                method="GET"
                class="silo-dashboard-search-form"
                onsubmit="const input = this.elements.namedItem('search'); if (input && !input.value.trim()) { input.disabled = true; }"
            >
                <label for="silo-dashboard-search" class="sr-only">Buscar documentos</label>
                <input
                    id="silo-dashboard-search"
                    type="search"
                    name="search"
                    class="silo-dashboard-search-input"
                    placeholder="Buscar documentos..."
                    aria-label="Buscar documentos"
                />

                <button
                    type="submit"
                    class="silo-dashboard-search-btn"
                >
                    <x-filament::icon
                        icon="heroicon-o-magnifying-glass"
                        class="silo-dashboard-search-btn__icon"
                    />
                    Buscar
                </button>
            </form>

            @if ($canCreateDocument)
                <a
                    href="{{ $links['createDocument'] }}"
                    class="silo-dashboard-create-btn"
                >
                    <x-filament::icon
                        icon="heroicon-o-plus"
                        class="silo-dashboard-create-btn__icon"
                    />
                    Nuevo documento
                </a>
            @endif
        </section>

        <section class="silo-dashboard-kpis">
            <a
                href="{{ $metricLinks['pending'] }}"
                class="silo-dashboard-kpi silo-dashboard-kpi--pending"
            >
                <div class="silo-dashboard-kpi__body">
                    <p class="silo-dashboard-kpi__label">Pendientes</p>
                    <p class="silo-dashboard-kpi__value">{{ number_format($metrics['pending']) }}</p>
                </div>

                <div class="silo-dashboard-kpi__icon-wrap">
                    <x-filament::icon
                        icon="heroicon-o-clock"
                        class="silo-dashboard-kpi__icon"
                    />
                </div>
            </a>

            <a
                href="{{ $metricLinks['approved'] }}"
                class="silo-dashboard-kpi silo-dashboard-kpi--approved"
            >
                <div class="silo-dashboard-kpi__body">
                    <p class="silo-dashboard-kpi__label">Aprobados</p>
                    <p class="silo-dashboard-kpi__value">{{ number_format($metrics['approved']) }}</p>
                </div>

                <div class="silo-dashboard-kpi__icon-wrap">
                    <x-filament::icon
                        icon="heroicon-o-check-circle"
                        class="silo-dashboard-kpi__icon"
                    />
                </div>
            </a>

            <a
                href="{{ $metricLinks['archived'] }}"
                class="silo-dashboard-kpi silo-dashboard-kpi--archived"
            >
                <div class="silo-dashboard-kpi__body">
                    <p class="silo-dashboard-kpi__label">Archivados</p>
                    <p class="silo-dashboard-kpi__value">{{ number_format($metrics['archived']) }}</p>
                </div>

                <div class="silo-dashboard-kpi__icon-wrap">
                    <x-filament::icon
                        icon="heroicon-o-archive-box"
                        class="silo-dashboard-kpi__icon"
                    />
                </div>
            </a>
        </section>

        @if (($unclassifiedAlert['count'] ?? 0) > 0)
            <section class="silo-dashboard-section silo-dashboard-alert">
                <div class="silo-dashboard-section__header">
                    <h2 class="silo-dashboard-section__title">Archivos Sin Clasificar</h2>
                    <a
                        href="{{ $unclassifiedAlert['filteredUrl'] }}"
                        class="silo-dashboard-link"
                    >
                        Revisar ahora
                    </a>
                </div>

                <article class="silo-dashboard-alert-card">
                    <p class="silo-dashboard-alert-card__title">
                        Hay {{ number_format($unclassifiedAlert['count']) }} archivo(s) importado(s) por fuera de la app.
                    </p>
                    <p class="silo-dashboard-alert-card__text">
                        Clasificalos para que entren al flujo editorial normal.
                    </p>
                </article>

                @if (count($unclassifiedAlert['items']) > 0)
                    <div class="silo-dashboard-review-list">
                        @foreach ($unclassifiedAlert['items'] as $item)
                            <article class="silo-dashboard-review-item">
                                <span class="silo-dashboard-review-item__icon">
                                    <x-filament::icon
                                        icon="heroicon-o-exclamation-triangle"
                                        class="silo-dashboard-review-item__icon-svg"
                                    />
                                </span>

                                <div class="silo-dashboard-review-item__content">
                                    <p class="silo-dashboard-review-item__title">{{ $item['title'] }}</p>
                                    <p class="silo-dashboard-review-item__meta">
                                        Ruta detectada: {{ $item['path'] ?? 'No disponible' }}
                                    </p>
                                </div>

                                <div class="silo-dashboard-review-item__actions">
                                    @if ($item['openUrl'])
                                        <a
                                            href="{{ $item['openUrl'] }}"
                                            class="silo-dashboard-open-btn"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            Abrir
                                        </a>
                                    @endif

                                    <a
                                        href="{{ $item['editUrl'] }}"
                                        class="silo-dashboard-edit-btn"
                                    >
                                        Clasificar
                                    </a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        <section class="silo-dashboard-section">
            <div class="silo-dashboard-section__header">
                <h2 class="silo-dashboard-section__title">Categorías Principales</h2>
                @if ($canCreateDocument)
                    <a
                        href="{{ $links['createDocument'] }}"
                        class="silo-dashboard-link"
                    >
                        Nuevo
                    </a>
                @endif
            </div>

            @if (count($topCategories) === 0)
                <article class="silo-dashboard-empty">
                    <p class="silo-dashboard-empty__title">Sin categorías con documentos</p>
                    <p class="silo-dashboard-empty__text">Crea documentos para visualizar las categorías principales.</p>
                </article>
            @else
                <div class="silo-dashboard-category-grid">
                    @foreach ($topCategories as $category)
                        <a
                            href="{{ $category['filteredUrl'] }}"
                            class="silo-dashboard-category-card"
                        >
                            <span
                                class="silo-dashboard-category-card__icon"
                                style="--silo-category-color: {{ $category['color'] }};"
                            >
                                <x-filament::icon
                                    :icon="$category['icon']"
                                    class="silo-dashboard-category-card__icon-svg"
                                />
                            </span>

                            <div class="silo-dashboard-category-card__meta">
                                <p class="silo-dashboard-category-card__name">{{ $category['name'] }}</p>
                                <p class="silo-dashboard-category-card__count">
                                    {{ number_format($category['count']) }} documentos
                                </p>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="silo-dashboard-section">
            <div class="silo-dashboard-section__header">
                <h2 class="silo-dashboard-section__title">Bandeja de Revisión</h2>
                <a
                    href="{{ $links['documentsIndex'] }}"
                    class="silo-dashboard-link"
                >
                    Ver todo
                </a>
            </div>

            @if (count($reviewQueue) === 0)
                <article class="silo-dashboard-empty">
                    <p class="silo-dashboard-empty__title">No hay documentos pendientes</p>
                    <p class="silo-dashboard-empty__text">Cuando existan documentos en revisión aparecerán aquí.</p>
                </article>
            @else
                <div class="silo-dashboard-review-list">
                    @foreach ($reviewQueue as $item)
                        <article class="silo-dashboard-review-item">
                            <span class="silo-dashboard-review-item__icon">
                                <x-filament::icon
                                    :icon="$item['icon']"
                                    class="silo-dashboard-review-item__icon-svg"
                                />
                            </span>

                            <div class="silo-dashboard-review-item__content">
                                <p class="silo-dashboard-review-item__title">{{ $item['title'] }}</p>
                                <p class="silo-dashboard-review-item__meta">
                                    @if ($item['entityName'])
                                        Entidad: {{ $item['entityName'] }} •
                                    @endif
                                    {{ $item['createdAtHuman'] ?? 'Sin fecha' }}
                                </p>
                            </div>

                            <div class="silo-dashboard-review-item__actions">
                                @if ($item['openUrl'])
                                    <a
                                        href="{{ $item['openUrl'] }}"
                                        class="silo-dashboard-open-btn"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Abrir
                                    </a>
                                @else
                                    <span class="silo-dashboard-open-btn silo-dashboard-open-btn--disabled">
                                        Abrir
                                    </span>
                                @endif

                                <a
                                    href="{{ $item['editUrl'] }}"
                                    class="silo-dashboard-edit-btn"
                                >
                                    Editar
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
