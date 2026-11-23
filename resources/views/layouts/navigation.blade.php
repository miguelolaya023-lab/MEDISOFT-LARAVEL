<aside id="medisoft-navigation" class="med-sidebar" x-ref="sidebar">
    <div class="med-sidebar-head">
        <a href="{{ route('dashboard') }}" class="med-sidebar-brand">
            <span class="med-sidebar-mark"><x-application-logo class="size-7 fill-current" /></span>
            <span>
                <span class="block text-lg font-semibold tracking-tight">MEDISOFT</span>
                <span class="block text-xs text-violet-200">Gestión del consultorio</span>
            </span>
        </a>
        <button type="button" class="med-drawer-close med-icon-button" aria-label="Cerrar navegación" autofocus @click="closeDrawer">
            <x-shell-icon name="close" />
        </button>
    </div>

    <nav aria-label="Navegación principal" class="med-sidebar-scroll">
        <x-shell-link :href="route('dashboard')" icon="home" :active="request()->routeIs('dashboard')">Inicio</x-shell-link>

        @canany(['consultarAgendaDiaria', 'create', 'createEps', 'reprogram', 'cancel', 'notificarCita'], \App\Models\Cita::class)
            <div class="med-nav-group" role="group" aria-labelledby="med-nav-agenda">
                <p id="med-nav-agenda" class="med-nav-label">Agenda y citas</p>
                @can('consultarAgendaDiaria', \App\Models\Cita::class)
                    <x-shell-link :href="route('agenda.diaria.index')" icon="calendar" :active="request()->routeIs('agenda.diaria.index')">Agenda diaria</x-shell-link>
                @endcan
                @can('create', \App\Models\Cita::class)
                    <x-shell-link :href="route('citas.create')" :active="request()->routeIs('citas.create', 'citas.confirmation')">Agendar particular</x-shell-link>
                @endcan
                @can('createEps', \App\Models\Cita::class)
                    <x-shell-link :href="route('citas.eps.create')" :active="request()->routeIs('citas.eps.*')">Agendar EPS</x-shell-link>
                @endcan
                @can('reprogram', \App\Models\Cita::class)
                    <x-shell-link :href="route('citas.reprogramar.index')" :active="request()->routeIs('citas.reprogramar.*')">Reprogramar</x-shell-link>
                @endcan
                @can('cancel', \App\Models\Cita::class)
                    <x-shell-link :href="route('citas.cancelar.index')" :active="request()->routeIs('citas.cancelar.*')">Cancelar cita</x-shell-link>
                @endcan
                @can('notificarCita', \App\Models\Cita::class)
                    <x-shell-link :href="route('notificaciones.citas.index')" icon="message" :active="request()->routeIs('notificaciones.citas.*')">Notificaciones de citas</x-shell-link>
                @endcan
            </div>
        @endcanany

        @canany(['viewAny', 'search', 'create'], \App\Models\Paciente::class)
            <div class="med-nav-group" role="group" aria-labelledby="med-nav-patients">
                <p id="med-nav-patients" class="med-nav-label">Pacientes</p>
                @can('viewAny', \App\Models\Paciente::class)
                    <x-shell-link :href="route('pacientes.index')" icon="people" :active="request()->routeIs('pacientes.index')">Listar pacientes</x-shell-link>
                @endcan
                @can('search', \App\Models\Paciente::class)
                    <x-shell-link :href="route('pacientes.search')" :active="request()->routeIs('pacientes.search', 'pacientes.show', 'pacientes.edit', 'pacientes.state', 'antecedentes.*')">Consultar paciente</x-shell-link>
                @endcan
                @can('create', \App\Models\Paciente::class)
                    <x-shell-link :href="route('pacientes.create')" :active="request()->routeIs('pacientes.create')">Registrar paciente</x-shell-link>
                @endcan
            </div>
        @endcanany

        @if (Gate::allows('verAgendaClinica', \App\Models\Cita::class) || Gate::allows('viewAny', \App\Models\DisponibilidadMedica::class))
            <div class="med-nav-group" role="group" aria-labelledby="med-nav-clinical">
                <p id="med-nav-clinical" class="med-nav-label">Área clínica</p>
                @can('verAgendaClinica', \App\Models\Cita::class)
                    <x-shell-link :href="route('atenciones.index')" icon="clinical" :active="request()->routeIs('atenciones.*')">Atención médica</x-shell-link>
                @endcan
                @can('viewAny', \App\Models\DisponibilidadMedica::class)
                    <x-shell-link :href="route('disponibilidad.index')" icon="clock" :active="request()->routeIs('disponibilidad.*')">Mi disponibilidad</x-shell-link>
                @endcan
            </div>
        @endif

        @canany(['viewAny', 'search', 'create'], \App\Models\Eps::class)
            <div class="med-nav-group" role="group" aria-labelledby="med-nav-eps">
                <p id="med-nav-eps" class="med-nav-label">EPS</p>
                @can('viewAny', \App\Models\Eps::class)
                    <x-shell-link :href="route('eps.index')" icon="shield" :active="request()->routeIs('eps.index')">Listar EPS</x-shell-link>
                @endcan
                @can('search', \App\Models\Eps::class)
                    <x-shell-link :href="route('eps.search')" :active="request()->routeIs('eps.search', 'eps.show', 'eps.edit')">Consultar EPS</x-shell-link>
                @endcan
                @can('create', \App\Models\Eps::class)
                    <x-shell-link :href="route('eps.create')" :active="request()->routeIs('eps.create')">Registrar EPS</x-shell-link>
                @endcan
            </div>
        @endcanany

        @canany(['viewAny', 'search', 'create'], \App\Models\RemisionEps::class)
            <div class="med-nav-group" role="group" aria-labelledby="med-nav-referrals">
                <p id="med-nav-referrals" class="med-nav-label">Remisiones</p>
                @can('viewAny', \App\Models\RemisionEps::class)
                    <x-shell-link :href="route('remisiones.index')" icon="document" :active="request()->routeIs('remisiones.index')">Listar remisiones</x-shell-link>
                @endcan
                @can('search', \App\Models\RemisionEps::class)
                    <x-shell-link :href="route('remisiones.search')" :active="request()->routeIs('remisiones.search', 'remisiones.show', 'remisiones.edit', 'remisiones.state')">Consultar remisiones</x-shell-link>
                @endcan
                @can('create', \App\Models\RemisionEps::class)
                    <x-shell-link :href="route('remisiones.create')" :active="request()->routeIs('remisiones.create')">Registrar remisión</x-shell-link>
                @endcan
            </div>
        @endcanany

        @can('viewAny', \App\Models\User::class)
            <div class="med-nav-group" role="group" aria-labelledby="med-nav-admin">
                <p id="med-nav-admin" class="med-nav-label">Administración</p>
                <x-shell-link :href="route('usuarios.index')" icon="people" :active="request()->routeIs('usuarios.*')">Usuarios</x-shell-link>
            </div>
        @endcan
    </nav>

    <div class="med-sidebar-footer">
        <x-shell-link :href="route('profile.edit')" icon="profile" :active="request()->routeIs('profile.edit')">Perfil</x-shell-link>
        <form method="POST" action="{{ route('logout') }}" onsubmit="return window.confirm('¿Cerrar sesión?')">
            @csrf
            <button type="submit" class="med-nav-link w-full text-left">
                <x-shell-icon name="logout" /> Cerrar sesión
            </button>
        </form>
    </div>
</aside>
<dialog class="med-drawer" x-ref="drawer" aria-label="Navegación de MEDISOFT"
    @click.self="closeDrawer" @cancel.prevent="closeDrawer"></dialog>
