import Alpine from 'alpinejs';

Alpine.data('medisoftShell', () => ({
    drawerOpen: false,

    init() {
        if (typeof this.$refs.drawer.showModal !== 'function') {
            return;
        }

        this.desktop = window.matchMedia('(min-width: 64rem)');
        this.closeOnDesktop = ({ matches }) => {
            if (matches) {
                this.closeDrawer();
            }
        };
        this.desktop.addEventListener('change', this.closeOnDesktop);
        this.$el.dataset.shellReady = '';
    },

    destroy() {
        this.desktop?.removeEventListener('change', this.closeOnDesktop);
    },

    openDrawer() {
        if (this.drawerOpen || this.desktop.matches) {
            return;
        }

        const { drawer, sidebar } = this.$refs;
        drawer.append(sidebar);
        drawer.showModal();
        this.drawerOpen = true;
    },

    closeDrawer() {
        const { drawer, sidebar, toggle } = this.$refs;
        if (!drawer.open) {
            return;
        }

        drawer.close();
        this.$el.prepend(sidebar);
        this.drawerOpen = false;

        if (!this.desktop.matches) {
            toggle.focus();
        }
    },
}));

Alpine.data('formularioFormulaMedica', () => ({
    filas: [0],
    siguienteFila: 1,

    get maximo() {
        return Number(this.$root.dataset.maximo);
    },

    agregar() {
        if (this.filas.length >= this.maximo) {
            return;
        }

        const fila = this.siguienteFila++;
        this.filas.push(fila);
        this.$nextTick(() => this.$root.querySelector('#formula-medicamento-' + fila)?.focus());
    },

    quitar(fila) {
        if (this.filas.length <= 1) {
            return;
        }

        const botonAgregar = this.$root.querySelector('#formula-agregar');
        this.filas = this.filas.filter((id) => id !== fila);
        this.$nextTick(() => botonAgregar?.focus());
    },
}));

window.Alpine = Alpine;

Alpine.start();
