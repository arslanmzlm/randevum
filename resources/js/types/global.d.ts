import type { SidebarMode } from '@/composables/useSidebar';
import type { UpcomingAppointmentDto } from '@/types/appointment';
import type { Auth } from '@/types/auth';
import type { SharedClinic } from '@/types/clinic';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            activeClinic: SharedClinic | null;
            /** Next N upcoming appointments for the viewer's scope; [] for guests / no permission. */
            upcomingAppointments: UpcomingAppointmentDto[];
            [key: string]: unknown;
        };
        layoutProps: {
            sidebar: SidebarMode;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
