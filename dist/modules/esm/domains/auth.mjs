const PROVIDES = Object.freeze(['authDomain']);
export const dependencies = Object.freeze(['core']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            const core = deps.core;
            function publish(session, serverVerified = false) {
                core?.patch?.('auth', {
                    type: session?.type || null,
                    authenticated: session?.type === 'email',
                    serverVerified: serverVerified === true,
                    name: String(session?.name || '')
                }, 'auth/publish');
            }
            function required() {
                core?.patch?.('auth', { authenticated: false, serverVerified: false }, 'auth/required');
                core?.events?.emit?.('auth-required-domain', {});
            }
            provided.authDomain = Object.freeze({ publish, required, current: () => core?.getState?.().auth || {} });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
