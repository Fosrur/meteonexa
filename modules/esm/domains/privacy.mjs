const PROVIDES = Object.freeze(['privacy']);
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
            const POLICY_VERSION = '20.1';
            function acknowledge(at = Date.now()) {
                const notice = { version: POLICY_VERSION, acknowledgedAt: Number(at) || Date.now() };
                core?.patch?.('privacy', { notice, policyVersion: POLICY_VERSION }, 'privacy/acknowledged');
                return notice;
            }
            function publish(notice) { core?.patch?.('privacy', { notice: notice || null, policyVersion: POLICY_VERSION }, 'privacy/publish'); }
            provided.privacy = Object.freeze({ policyVersion: POLICY_VERSION, acknowledge, publish, current: () => core?.getState?.().privacy || {} });
        })();
        }
    });
}

export const serviceNames = PROVIDES;
