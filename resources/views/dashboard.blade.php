<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">Panel de MEDISOFT</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 pb-10 pt-5 sm:px-6 lg:px-8 lg:pt-6">
        <section aria-labelledby="med-welcome" class="med-hero relative isolate overflow-hidden rounded-[1.5rem] px-6 py-7 sm:px-10">
            <div class="med-hero-art" aria-hidden="true">
                <div class="med-hero-orbit"></div>
                <div class="med-hero-emblem"><x-application-logo class="size-16 fill-current sm:size-20" /></div>
            </div>
            <div class="relative z-10 max-w-xl">
                <p class="mb-3 text-xs font-semibold uppercase tracking-[0.2em] text-violet-200">Consultorio médico</p>
                <h2 id="med-welcome" class="break-words text-3xl font-semibold leading-tight tracking-tight text-white sm:text-4xl">
                    Bienvenido, {{ Auth::user()->name }}
                </h2>
                <p class="mt-2 max-w-md text-sm leading-relaxed text-violet-100 sm:text-base">
                    Gestiona la agenda, los pacientes y la atención del consultorio desde tu espacio de trabajo.
                </p>
                @can('consultarAgendaDiaria', \App\Models\Cita::class)
                    <a href="{{ route('agenda.diaria.index') }}" class="med-hero-action mt-4 inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold">
                        <x-shell-icon name="calendar" /> Abrir agenda diaria
                    </a>
                @endcan
            </div>
        </section>

        <section class="mt-6" aria-labelledby="med-shortcuts">
            <h2 id="med-shortcuts" class="text-xl font-semibold tracking-tight text-slate-900">Accesos rápidos</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @can('consultarAgendaDiaria', \App\Models\Cita::class)
                    <x-dashboard-link :href="route('agenda.diaria.index')" icon="calendar" title="Agenda diaria">
                        Consulta las citas programadas para una fecha y sus estados.
                    </x-dashboard-link>
                @endcan
                @can('create', \App\Models\Cita::class)
                    <x-dashboard-link :href="route('citas.create')" icon="calendar" title="Agendar particular">
                        Consulta los horarios disponibles y registra una cita particular.
                    </x-dashboard-link>
                @endcan
                @can('createEps', \App\Models\Cita::class)
                    <x-dashboard-link :href="route('citas.eps.create')" icon="shield" title="Agendar EPS">
                        Programa una cita vinculada a una remisión EPS.
                    </x-dashboard-link>
                @endcan
                @can('viewAny', \App\Models\Paciente::class)
                    <x-dashboard-link :href="route('pacientes.index')" icon="people" title="Pacientes">
                        Consulta el listado de pacientes del consultorio.
                    </x-dashboard-link>
                @elsecan('search', \App\Models\Paciente::class)
                    <x-dashboard-link :href="route('pacientes.search')" icon="people" title="Consultar paciente">
                        Busca un paciente y consulta su ficha administrativa.
                    </x-dashboard-link>
                @elsecan('create', \App\Models\Paciente::class)
                    <x-dashboard-link :href="route('pacientes.create')" icon="people" title="Registrar paciente">
                        Registra un paciente en el consultorio.
                    </x-dashboard-link>
                @endcan
                @can('verAgendaClinica', \App\Models\Cita::class)
                    <x-dashboard-link :href="route('atenciones.index')" icon="clinical" title="Atención médica">
                        Accede a la agenda clínica y a las atenciones disponibles.
                    </x-dashboard-link>
                @endcan
                @can('viewAny', \App\Models\DisponibilidadMedica::class)
                    <x-dashboard-link :href="route('disponibilidad.index')" icon="clock" title="Mi disponibilidad">
                        Consulta y organiza tus bloques de atención.
                    </x-dashboard-link>
                @endcan
                @can('notificarCita', \App\Models\Cita::class)
                    <x-dashboard-link :href="route('notificaciones.citas.index')" icon="message" title="Notificaciones de citas">
                        Consulta la bandeja interna de avisos simulados.
                    </x-dashboard-link>
                @endcan
                @can('viewAny', \App\Models\Eps::class)
                    <x-dashboard-link :href="route('eps.index')" icon="shield" title="EPS">
                        Consulta las entidades registradas en el consultorio.
                    </x-dashboard-link>
                @endcan
                @can('viewAny', \App\Models\RemisionEps::class)
                    <x-dashboard-link :href="route('remisiones.index')" icon="document" title="Remisiones">
                        Revisa las remisiones EPS registradas y su estado.
                    </x-dashboard-link>
                @endcan
                @can('viewAny', \App\Models\User::class)
                    <x-dashboard-link :href="route('usuarios.index')" icon="people" title="Usuarios">
                        Accede a la administración de cuentas internas.
                    </x-dashboard-link>
                @endcan
                <x-dashboard-link :href="route('profile.edit')" icon="profile" title="Mi perfil">
                    Consulta y actualiza la información de tu cuenta.
                </x-dashboard-link>
            </div>
        </section>
    </div>
</x-app-layout>
