<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'SILO') }} - Sistema de Gestión Documental</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --color-primary: #1d6362;
            --color-primary-dark: #153f3e;
            --color-primary-light: #2a8482;
            --color-success: #6b9a34;
            --color-info: #99ce93;
            --color-warning: #f8c508;
            --color-danger: #f50404;
            --color-white: #ffffff;
            --color-gray-50: #f9fafb;
            --color-gray-100: #f3f4f6;
            --color-gray-200: #e5e7eb;
            --color-gray-300: #d1d5db;
            --color-gray-600: #4b5563;
            --color-gray-700: #374151;
            --color-gray-800: #1f2937;
            --color-gray-900: #111827;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.6;
            color: var(--color-gray-800);
            background: linear-gradient(135deg, var(--color-gray-50) 0%, var(--color-white) 100%);
            min-height: 100vh;
        }

        /* Header/Navbar */
        .header {
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .navbar {
            max-width: 1280px;
            margin: 0 auto;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo {
            width: 48px;
            height: 48px;
            background: var(--color-white);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.5rem;
            color: var(--color-primary);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .brand-name {
            color: var(--color-white);
        }

        .brand-name h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.125rem;
        }

        .brand-name p {
            font-size: 0.875rem;
            opacity: 0.9;
            font-weight: 400;
        }

        .nav-buttons {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            padding: 0.625rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9375rem;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
            border: 2px solid transparent;
        }

        .btn-outline {
            color: var(--color-white);
            border-color: rgba(255, 255, 255, 0.3);
            background: transparent;
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--color-white);
            transform: translateY(-2px);
        }

        .btn-primary {
            background: var(--color-white);
            color: var(--color-primary);
            border-color: var(--color-white);
        }

        .btn-primary:hover {
            background: var(--color-gray-50);
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        /* Hero Section */
        .hero {
            max-width: 1280px;
            margin: 0 auto;
            padding: 4rem 2rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
            min-height: calc(100vh - 100px);
        }

        .hero-content h2 {
            font-size: 3rem;
            font-weight: 800;
            color: var(--color-gray-900);
            margin-bottom: 1.5rem;
            line-height: 1.2;
        }

        .hero-content .highlight {
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-success) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-content p {
            font-size: 1.25rem;
            color: var(--color-gray-600);
            margin-bottom: 2rem;
            line-height: 1.8;
        }

        .hero-cta {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn-large {
            padding: 1rem 2rem;
            font-size: 1.125rem;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--color-success) 0%, var(--color-primary) 100%);
            color: var(--color-white);
            border: none;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px rgba(107, 154, 52, 0.3);
        }

        .hero-image {
            position: relative;
            animation: float 6s ease-in-out infinite;
        }

        .hero-image img {
            width: 100%;
            height: auto;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-20px);
            }
        }

        /* Features Section */
        .features {
            background: var(--color-white);
            padding: 5rem 2rem;
        }

        .features-container {
            max-width: 1280px;
            margin: 0 auto;
        }

        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }

        .section-header h3 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--color-gray-900);
            margin-bottom: 1rem;
        }

        .section-header p {
            font-size: 1.125rem;
            color: var(--color-gray-600);
            max-width: 600px;
            margin: 0 auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            background: linear-gradient(135deg, var(--color-white) 0%, var(--color-gray-50) 100%);
            padding: 2rem;
            border-radius: 16px;
            border: 2px solid var(--color-gray-100);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--color-primary) 0%, var(--color-success) 100%);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: var(--color-primary);
        }

        .feature-card:hover::before {
            transform: scaleX(1);
        }

        .feature-icon {
            width: 64px;
            height: 64px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            font-size: 2rem;
        }

        .feature-card:nth-child(1) .feature-icon {
            background: linear-gradient(135deg, rgba(29, 99, 98, 0.1) 0%, rgba(29, 99, 98, 0.2) 100%);
            color: var(--color-primary);
        }

        .feature-card:nth-child(2) .feature-icon {
            background: linear-gradient(135deg, rgba(107, 154, 52, 0.1) 0%, rgba(107, 154, 52, 0.2) 100%);
            color: var(--color-success);
        }

        .feature-card:nth-child(3) .feature-icon {
            background: linear-gradient(135deg, rgba(153, 206, 147, 0.1) 0%, rgba(153, 206, 147, 0.2) 100%);
            color: var(--color-success);
        }

        .feature-card:nth-child(4) .feature-icon {
            background: linear-gradient(135deg, rgba(29, 99, 98, 0.1) 0%, rgba(107, 154, 52, 0.2) 100%);
            color: var(--color-primary);
        }

        .feature-card h4 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--color-gray-900);
            margin-bottom: 0.75rem;
        }

        .feature-card p {
            color: var(--color-gray-600);
            line-height: 1.7;
        }

        /* Footer */
        .footer {
            background: linear-gradient(135deg, var(--color-primary-dark) 0%, var(--color-gray-900) 100%);
            color: var(--color-white);
            padding: 3rem 2rem 2rem;
        }

        .footer-container {
            max-width: 1280px;
            margin: 0 auto;
        }

        .footer-content {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 3rem;
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .footer-about h5 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .footer-about p {
            opacity: 0.8;
            line-height: 1.7;
        }

        .footer-links h6 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .footer-links ul {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.5rem;
        }

        .footer-links a {
            color: var(--color-white);
            text-decoration: none;
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }

        .footer-links a:hover {
            opacity: 1;
        }

        .footer-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.875rem;
            opacity: 0.8;
        }

        .footer-bottom a {
            color: var(--color-info);
            text-decoration: none;
            font-weight: 600;
        }

        .footer-bottom a:hover {
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .hero {
                grid-template-columns: 1fr;
                gap: 3rem;
                padding: 3rem 1.5rem;
            }

            .hero-content h2 {
                font-size: 2.5rem;
            }

            .footer-content {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 1rem;
            }

            .brand-name h1 {
                font-size: 1.25rem;
            }

            .brand-name p {
                display: none;
            }

            .nav-buttons {
                gap: 0.5rem;
            }

            .btn {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }

            .hero-content h2 {
                font-size: 2rem;
            }

            .hero-content p {
                font-size: 1.125rem;
            }

            .section-header h3 {
                font-size: 2rem;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .footer-bottom {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>

<body>
    <!-- Header/Navbar -->
    <header class="header">
        <nav class="navbar">
            <div class="logo-section">
                <div class="logo">S</div>
                <div class="brand-name">
                    <h1>SILO</h1>
                    <p>Sistema de Gestión Documental</p>
                </div>
            </div>

            <div class="nav-buttons">
                @auth
                    <a href="{{ url('/admin') }}" class="btn btn-primary">Dashboard</a>
                @else
                    <a href="{{ url('/admin/login') }}" class="btn btn-outline">Acceder</a>
                @endauth
            </div>
        </nav>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <h2>
                Gestión Documental <span class="highlight">Inteligente y Eficiente</span>
            </h2>
            <p>
                Sistema integral de gestión documental para la IED Agropecuaria José María Herrera.
                Organiza, clasifica y accede a tus documentos institucionales de manera rápida y segura.
            </p>
            <div class="hero-cta">
                @auth
                    <a href="{{ url('/admin') }}" class="btn btn-success btn-large">Ir al Panel</a>
                @else
                    <a href="{{ url('/admin/login') }}" class="btn btn-success btn-large">Comenzar Ahora</a>
                @endauth
            </div>
        </div>

        <div class="hero-image">
            <img src="{{ asset('images/document-management-hero.png') }}" alt="Gestión Documental"
                onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22600%22 height=%22400%22%3E%3Crect fill=%22%231d6362%22 width=%22600%22 height=%22400%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 font-family=%22Arial%22 font-size=%2224%22 fill=%22white%22%3EGestión Documental%3C/text%3E%3C/svg%3E'">
        </div>
    </section>

    <!-- Features Section -->
    <section class="features">
        <div class="features-container">
            <div class="section-header">
                <h3>Funcionalidades Principales</h3>
                <p>Descubre todas las herramientas que SILO pone a tu disposición para una gestión documental eficiente
                </p>
            </div>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">📁</div>
                    <h4>Gestión Documental</h4>
                    <p>
                        Almacenamiento y organización automática en Google Drive.
                        Estructura jerárquica por año y categoría para un acceso rápido y ordenado.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🏷️</div>
                    <h4>Categorización Inteligente</h4>
                    <p>
                        Clasifica documentos por tipo (Resoluciones, Actas, Circulares), año y entidad.
                        Sistema de etiquetas personalizables para mejor organización.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🔍</div>
                    <h4>Búsqueda Avanzada</h4>
                    <p>
                        Encuentra documentos rápidamente con filtros por categoría, año, entidad y metadatos.
                        Búsqueda global en títulos y contenido.
                    </p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🔒</div>
                    <h4>Acceso Controlado</h4>
                    <p>
                        Sistema de permisos y roles con Filament Shield.
                        Control granular sobre quién puede ver, editar o eliminar documentos.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-about">
                    <h5>SILO - Sistema de Gestión Documental</h5>
                    <p>
                        Plataforma desarrollada para optimizar la gestión documental institucional
                        de la IED Agropecuaria José María Herrera, facilitando el acceso,
                        organización y control de documentos importantes.
                    </p>
                </div>

                <div class="footer-links">
                    <h6>Enlaces Rápidos</h6>
                    <ul>
                        <li><a href="{{ url('/admin/login') }}">Acceder</a></li>
                        <li><a href="{{ url('/admin') }}">Panel de Control</a></li>
                    </ul>
                </div>

                <div class="footer-links">
                    <h6>Información</h6>
                    <ul>
                        <li><a href="https://www.asyservicios.com" target="_blank">Desarrolladora</a></li>
                        <li><a href="#">Soporte Técnico</a></li>
                        <li><a href="#">Documentación</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <div>
                    © {{ date('Y') }} SILO. Todos los derechos reservados.
                    Desarrollado por <a href="https://www.asyservicios.com" target="_blank">AS&Servicios.com</a>
                </div>
                <div>
                    Propiedad de <strong>IED Agropecuaria José María Herrera</strong>
                </div>
            </div>
        </div>
    </footer>
</body>

</html>