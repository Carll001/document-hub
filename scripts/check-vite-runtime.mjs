import { createRequire } from 'node:module';
import os from 'node:os';
import process from 'node:process';

const require = createRequire(import.meta.url);
const cwd = process.cwd();
const isWindows = process.platform === 'win32';
const isWslShare = /^\\\\wsl(\.localhost)?\\/i.test(cwd);

if (isWindows && isWslShare) {
    console.error(
        [
            'Vite cannot run reliably here because Windows Node is using a WSL filesystem path.',
            `Current working directory: ${cwd}`,
            '',
            'Use one of these setups instead:',
            '1. Start the frontend from a WSL shell so Linux Node reads /home/... directly.',
            '2. Move the project into a native Windows path before running Windows Node.',
            '',
            'This mismatch can surface as ERR_CONNECTION_RESET, failed dynamic imports,',
            'or missing native bundler bindings during Vite startup.',
        ].join(os.EOL),
    );

    process.exit(1);
}

if (isWindows) {
    try {
        require.resolve('@rolldown/binding-win32-x64-msvc');
    } catch {
        console.error(
            [
                'Missing Vite native dependency: @rolldown/binding-win32-x64-msvc',
                '',
                'Reinstall dependencies in a native Windows project path, or run the frontend',
                'from WSL so the Linux-native binding is installed and loaded instead.',
            ].join(os.EOL),
        );

        process.exit(1);
    }
}
