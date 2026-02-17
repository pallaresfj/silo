<x-filament-panels::page.simple>
    <div class="silo-access-page">
        <div class="silo-access-blob silo-access-blob--left" aria-hidden="true"></div>
        <div class="silo-access-blob silo-access-blob--right" aria-hidden="true"></div>

        <main class="silo-access-main">
            <header class="silo-access-brand">
                <div class="silo-access-brand-row">
                    <svg class="silo-access-brand-icon" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <path
                            fill="currentColor"
                            fill-rule="evenodd"
                            clip-rule="evenodd"
                            d="M12.08 24L4 19.2479L9.95537 8.75216L18.04 13.4961L18.0446 4H29.9554L29.96 13.4961L38.0446 8.75216L44 19.2479L35.92 24L44 28.7521L38.0446 39.2479L29.96 34.5039L29.9554 44H18.0446L18.04 34.5039L9.95537 39.2479L4 28.7521L12.08 24Z"
                        />
                    </svg>
                    <h1>SILO</h1>
                </div>
                <p>SISTEMA DE GESTIÓN DOCUMENTAL</p>
            </header>

            <section class="silo-access-card" aria-labelledby="silo-access-title">
                <div class="silo-access-card-accent"></div>

                <div class="silo-access-card-body">
                    <header class="silo-access-card-header">
                        <h2 id="silo-access-title">Acceso Institucional</h2>
                        <p>Utilice sus credenciales educativas para ingresar al ecosistema de aprendizaje.</p>
                    </header>

                    @if ($errors->has('sso') || $errors->has('auth'))
                        <div class="silo-access-alert" role="alert">
                            {{ $errors->first('sso') ?: $errors->first('auth') }}
                        </div>
                    @endif

                    <div class="silo-access-illustration" aria-hidden="true">
                        <svg class="silo-access-illustration-icon" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M18 10V7H14.5l-2-2H9v3h2.67l1.6 1.6V10H9.5A4.5 4.5 0 0 0 5 14.5V15a3 3 0 1 0 6 0v-.5c0-.53-.14-1.03-.38-1.46h5.76A4.5 4.5 0 0 0 16 14.5V15a3 3 0 1 0 6 0v-.5A4.5 4.5 0 0 0 18 10Zm-10 8a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm11 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/>
                        </svg>
                    </div>

                    <a class="silo-access-button" href="{{ route('sso.login') }}">
                        <svg class="silo-access-button-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M10 17v-3H3v-4h7V7l5 5-5 5Zm9-12h-6v2h6v10h-6v2h6a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Z"/>
                        </svg>
                        <span>Ingresar con Cuenta Institucional</span>
                    </a>
                </div>
            </section>

            <footer class="silo-access-legal">
                <p>
                    © 2026 SILO - IED Agropecuaria José María Herrera. Desarrollado por
                    <a href="https://www.asyservicios.com" target="_blank" rel="noreferrer noopener">AS&amp;Servicios.com</a>
                </p>
            </footer>
        </main>
    </div>
</x-filament-panels::page.simple>
