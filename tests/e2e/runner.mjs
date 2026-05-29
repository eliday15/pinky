/**
 * E2E Test Runner — Orchestrator.
 *
 * Prepares an isolated testing environment using SQLite, runs Puppeteer tests,
 * and cleans up after execution.
 *
 * Steps:
 * 1. Create SQLite database file
 * 2. Run migrations and seed E2E test data
 * 3. Build frontend assets
 * 4. Start Laravel dev server on port 8787
 * 5. Run all *.test.mjs files via node:test
 * 6. Kill the server and delete the test database
 */

import { execSync, spawn } from 'node:child_process';
import { existsSync, unlinkSync, writeFileSync, readFileSync, readdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as sleep } from 'node:timers/promises';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '../..');
const DB_PATH = resolve(ROOT, 'database/testing.sqlite');
const PORT = 8787;
const BASE_URL = `http://127.0.0.1:${PORT}`;

let serverProcess = null;

/**
 * Execute a shell command synchronously in the project root.
 *
 * Args:
 *     cmd: Command string to execute
 *     label: Description for logging
 *     allowFail: If true, swallow errors and return false
 *
 * Returns:
 *     true on success, false on failure (when allowFail is true)
 */
function run(cmd, label, allowFail = false) {
    console.log(`\n→ ${label}`);
    try {
        execSync(cmd, { cwd: ROOT, stdio: 'inherit' });
        return true;
    } catch (err) {
        if (allowFail) {
            console.log(`  (allowed failure — continuing)`);
            return false;
        }
        throw err;
    }
}

/**
 * Wait until the Laravel server responds on the configured port.
 *
 * Args:
 *     maxWaitMs: Maximum milliseconds to wait before timing out
 *
 * Raises:
 *     Error: If the server does not respond within maxWaitMs
 */
async function waitForServer(maxWaitMs = 30000) {
    const start = Date.now();
    while (Date.now() - start < maxWaitMs) {
        try {
            const res = await fetch(BASE_URL);
            if (res.ok || res.status === 302 || res.status === 200) {
                console.log(`  Server ready (${Date.now() - start}ms)`);
                return;
            }
        } catch {
            // Server not up yet
        }
        await sleep(500);
    }
    throw new Error(`Server did not start within ${maxWaitMs}ms`);
}

/**
 * Clean up test artifacts: kill the server and delete the database.
 */
function cleanup() {
    console.log('\n→ Cleaning up...');
    if (serverProcess) {
        serverProcess.kill('SIGTERM');
        serverProcess = null;
        console.log('  Server stopped.');
    }
    if (existsSync(DB_PATH)) {
        unlinkSync(DB_PATH);
        console.log('  Test database deleted.');
    }
}

async function main() {
    // Trap exit signals for cleanup
    process.on('SIGINT', () => { cleanup(); process.exit(1); });
    process.on('SIGTERM', () => { cleanup(); process.exit(1); });

    try {
        console.log('=== E2E Test Runner ===\n');

        // 1. Create empty SQLite file
        if (existsSync(DB_PATH)) unlinkSync(DB_PATH);
        writeFileSync(DB_PATH, '');
        console.log(`  SQLite DB created at ${DB_PATH}`);

        // Patch DB_DATABASE in .env.testing with absolute path for this machine
        const envPath = resolve(ROOT, '.env.testing');
        let envContent = readFileSync(envPath, 'utf-8');
        envContent = envContent.replace(
            /^DB_DATABASE=.*$/m,
            `DB_DATABASE=${DB_PATH}`
        );
        writeFileSync(envPath, envContent);
        console.log(`  .env.testing updated with DB path`);

        // 2. Migrate & seed
        run(
            'php artisan migrate:fresh --env=testing --seed --seeder=E2ETestSeeder --force',
            'Running migrations and E2E seeder...'
        );

        // 3. Build frontend
        run('npx vite build', 'Building frontend assets...');

        // Remove Vite hot file so Laravel uses built assets instead of dev server
        const hotFile = resolve(ROOT, 'public/hot');
        if (existsSync(hotFile)) {
            unlinkSync(hotFile);
            console.log('  Removed public/hot (force production assets)');
        }

        // 4. Start Laravel server via shell redirect so stdout/stderr go to a real
        // file. `php artisan serve` uses `file_put_contents('php://stdout', ...)` for
        // access logs; if those writes get EPIPE (e.g. due to how Node inherits fds
        // through the artisan→php-S→worker chain), the server crashes mid-request.
        // A shell-level `>file 2>&1` redirect gives every descended process a
        // writable file fd that never causes EPIPE, regardless of what Node does to
        // its own stdio descriptors.
        console.log('\n→ Starting Laravel server...');
        const serverLogPath = resolve(ROOT, 'storage/logs/e2e-server.log');
        serverProcess = spawn(
            'sh',
            ['-c', `php artisan serve --env=testing --port=${PORT} >"${serverLogPath}" 2>&1`],
            { cwd: ROOT, stdio: 'ignore', detached: false }
        );

        await waitForServer();

        // 5. Run tests
        // Enumerate spec files explicitly (don't rely on shell glob expansion)
        // and run them serially: every spec launches its own browser and shares
        // the single dev server on PORT, so concurrency would cause contention.
        console.log('\n→ Running E2E tests...\n');
        const specFiles = readdirSync(__dirname)
            .filter((f) => f.endsWith('.test.mjs'))
            .sort()
            .map((f) => resolve(__dirname, f));

        if (specFiles.length === 0) {
            throw new Error('No *.test.mjs spec files found in tests/e2e');
        }
        console.log(`  Discovered ${specFiles.length} spec file(s):`);
        specFiles.forEach((f) => console.log(`    - ${f.split('/').pop()}`));

        try {
            // --test-timeout caps any single hung test (e.g. a selector that never
            // appears) so the suite fails fast instead of blocking indefinitely.
            execSync(
                `node --test --test-concurrency=1 --test-timeout=60000 ${specFiles.map((f) => `"${f}"`).join(' ')}`,
                { cwd: ROOT, stdio: 'inherit', env: { ...process.env, E2E_BASE_URL: BASE_URL } }
            );
            console.log('\n✓ All E2E tests passed!');
        } catch (err) {
            console.error('\n✗ Some tests failed.');
            process.exitCode = 1;
        }
    } catch (err) {
        console.error('\n✗ Runner error:', err.message);
        process.exitCode = 1;
    } finally {
        cleanup();
    }
}

main();
