// Local dev override for the plain-localhost stack (docker-compose.local.yml).
// The committed vite.config.js points the HMR client at port 80 of the Traefik
// host (vite.solidtime.test); here Vite is reached directly on localhost:5173.
import baseConfig from './vite.config.js';

export default (async () => {
    const config = await baseConfig;

    config.server = {
        ...config.server,
        host: true,
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
            protocol: 'ws',
            clientPort: 5173,
        },
    };

    return config;
})();
