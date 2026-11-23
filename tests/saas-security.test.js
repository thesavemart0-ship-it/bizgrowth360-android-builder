/**
 * Multi-Tenant SaaS Security & IDOR Verification Test Suite
 *
 * Verifies tenant isolation, ownership enforcement, IDOR prevention,
 * rate limiting, plan quotas, workspace suspension, and artifact download authorization.
 */

const { test, describe } = require('node:test');
const assert = require('node:assert/strict');

// Simulate the SaaS data structures and TenantManager isolation engine
class MockSaasPlatform {
    constructor() {
        this.plans = [
            { id: 1, name: 'Starter', slug: 'starter', max_apps: 2, max_monthly_builds: 5, features: { push: true, offline: false } },
            { id: 2, name: 'Pro', slug: 'pro', max_apps: 10, max_monthly_builds: 50, features: { push: true, offline: true } },
            { id: 3, name: 'Agency / Enterprise', slug: 'enterprise', max_apps: 100, max_monthly_builds: 500, features: { push: true, offline: true } }
        ];

        this.workspaces = [];
        this.apps = [];
        this.builds = [];
        this.rateLimits = new Map(); // key -> { count, timestamp }
    }

    createWorkspace({ id, name, slug, ownerEmail, planId = 1, status = 'active' }) {
        const ws = { id, name, slug, ownerEmail, planId, status, createdAt: new Date() };
        this.workspaces.push(ws);
        return ws;
    }

    createApp({ id, workspaceId, name, packageId, url }) {
        const app = { id, workspaceId, name, packageId, url, createdAt: new Date() };
        this.apps.push(app);
        return app;
    }

    createBuild({ buildId, workspaceId, appId, target = 'both', status = 'queued' }) {
        const build = { buildId, workspaceId, appId, target, status, artifacts: [] };
        this.builds.push(build);
        return build;
    }

    checkRateLimit(key, maxAttempts = 5, windowMs = 900000) {
        const now = Date.now();
        const record = this.rateLimits.get(key) || { count: 0, resetAt: now + windowMs };

        if (now > record.resetAt) {
            record.count = 0;
            record.resetAt = now + windowMs;
        }

        if (record.count >= maxAttempts) {
            return false; // Rate limit exceeded
        }

        record.count++;
        this.rateLimits.set(key, record);
        return true;
    }

    // Tenant Manager Access Control
    validateAppAccess(appId, requestingWorkspaceId, isSuperAdmin = false) {
        if (isSuperAdmin) return { allowed: true };
        const app = this.apps.find(a => a.id === appId);
        if (!app) return { allowed: false, code: 404, message: 'App not found' };
        if (app.workspaceId !== requestingWorkspaceId) {
            return { allowed: false, code: 403, message: 'Access denied: App belongs to another tenant (IDOR prevented)' };
        }
        return { allowed: true, app };
    }

    validateBuildAccess(buildId, requestingWorkspaceId, isSuperAdmin = false) {
        if (isSuperAdmin) return { allowed: true };
        const build = this.builds.find(b => b.buildId === buildId);
        if (!build) return { allowed: false, code: 404, message: 'Build not found' };
        if (build.workspaceId !== requestingWorkspaceId) {
            return { allowed: false, code: 403, message: 'Access denied: Build belongs to another tenant (IDOR prevented)' };
        }
        return { allowed: true, build };
    }

    canCreateApp(workspaceId) {
        const ws = this.workspaces.find(w => w.id === workspaceId);
        if (!ws) return { allowed: false, error: 'Workspace not found' };
        if (ws.status !== 'active') return { allowed: false, error: 'workspace_suspended' };

        const plan = this.plans.find(p => p.id === ws.planId);
        const currentAppCount = this.apps.filter(a => a.workspaceId === workspaceId).length;

        if (currentAppCount >= plan.max_apps) {
            return { allowed: false, error: 'plan_limit_exceeded', max: plan.max_apps, current: currentAppCount };
        }
        return { allowed: true };
    }

    canTriggerBuild(workspaceId) {
        const ws = this.workspaces.find(w => w.id === workspaceId);
        if (!ws) return { allowed: false, error: 'Workspace not found' };
        if (ws.status !== 'active') return { allowed: false, error: 'workspace_suspended' };

        const plan = this.plans.find(p => p.id === ws.planId);
        const monthlyBuildsCount = this.builds.filter(b => b.workspaceId === workspaceId).length;

        if (monthlyBuildsCount >= plan.max_monthly_builds) {
            return { allowed: false, error: 'build_quota_exceeded', max: plan.max_monthly_builds, current: monthlyBuildsCount };
        }
        return { allowed: true };
    }

    validateArtifactDownload(buildId, artifactId, requestingWorkspaceId, isSuperAdmin = false) {
        if (isSuperAdmin) return { allowed: true };
        const build = this.builds.find(b => b.buildId === buildId);
        if (!build) return { allowed: false, code: 404 };
        if (build.workspaceId !== requestingWorkspaceId) {
            return { allowed: false, code: 403, message: 'Cross-tenant artifact access blocked' };
        }
        return { allowed: true };
    }
}

describe('SaaS Multi-Tenant Security & IDOR Prevention', () => {
    let platform;

    test('setup mock multi-tenant platform', () => {
        platform = new MockSaasPlatform();
        // Super Admin workspace #1
        platform.createWorkspace({ id: 1, name: 'Bizgrowth360 Platform', slug: 'admin', ownerEmail: 'admin@bizgrowth360.in', planId: 3 });
        // Customer Tenant A
        platform.createWorkspace({ id: 2, name: 'Acme Retailers', slug: 'acme-retailers', ownerEmail: 'owner@acme.com', planId: 1 });
        // Customer Tenant B
        platform.createWorkspace({ id: 3, name: 'Zeta Logistics', slug: 'zeta-logistics', ownerEmail: 'admin@zeta.com', planId: 2 });

        // Apps
        platform.createApp({ id: 101, workspaceId: 2, name: 'Acme App', packageId: 'com.acme.app', url: 'https://acme.com' });
        platform.createApp({ id: 102, workspaceId: 3, name: 'Zeta Driver App', packageId: 'com.zeta.driver', url: 'https://zeta.com' });

        // Builds
        platform.createBuild({ buildId: 'bg360_bld_acme1', workspaceId: 2, appId: 101 });
        platform.createBuild({ buildId: 'bg360_bld_zeta1', workspaceId: 3, appId: 102 });

        assert.equal(platform.workspaces.length, 3);
        assert.equal(platform.apps.length, 2);
    });

    test('Tenant A can access and manage their own app', () => {
        const access = platform.validateAppAccess(101, 2, false);
        assert.equal(access.allowed, true);
        assert.equal(access.app.name, 'Acme App');
    });

    test('IDOR Protection: Tenant A CANNOT access, view or edit Tenant B app (HTTP 403)', () => {
        const access = platform.validateAppAccess(102, 2, false); // Tenant A (ws 2) accessing Tenant B app (id 102)
        assert.equal(access.allowed, false);
        assert.equal(access.code, 403);
        assert.match(access.message, /IDOR prevented/);
    });

    test('IDOR Protection: Tenant B CANNOT access Tenant A app (HTTP 403)', () => {
        const access = platform.validateAppAccess(101, 3, false);
        assert.equal(access.allowed, false);
        assert.equal(access.code, 403);
    });

    test('Super Admin has global access to all tenant apps', () => {
        const accessA = platform.validateAppAccess(101, 1, true);
        const accessB = platform.validateAppAccess(102, 1, true);
        assert.equal(accessA.allowed, true);
        assert.equal(accessB.allowed, true);
    });

    test('Build IDOR Protection: Tenant A CANNOT access Tenant B build record', () => {
        const access = platform.validateBuildAccess('bg360_bld_zeta1', 2, false);
        assert.equal(access.allowed, false);
        assert.equal(access.code, 403);
    });

    test('Artifact Download Security: Tenant A CANNOT download Tenant B build artifacts', () => {
        const access = platform.validateArtifactDownload('bg360_bld_zeta1', 999, 2, false);
        assert.equal(access.allowed, false);
        assert.equal(access.code, 403);
    });

    test('Artifact Download Security: Tenant A CAN download their own build artifacts', () => {
        const access = platform.validateArtifactDownload('bg360_bld_acme1', 888, 2, false);
        assert.equal(access.allowed, true);
    });

    test('Plan Quota: Starter plan allows creating apps up to plan limit (max 2)', () => {
        // Tenant A currently has 1 app (max 2)
        const check1 = platform.canCreateApp(2);
        assert.equal(check1.allowed, true);

        // Add 2nd app
        platform.createApp({ id: 103, workspaceId: 2, name: 'Acme Second App', packageId: 'com.acme.two', url: 'https://two.acme.com' });

        // Try adding 3rd app (exceeds limit)
        const check2 = platform.canCreateApp(2);
        assert.equal(check2.allowed, false);
        assert.equal(check2.error, 'plan_limit_exceeded');
    });

    test('Plan Quota: Build limit enforcement rejects builds after quota exhaustion', () => {
        // Tenant A on Starter plan has limit of 5 builds. Currently has 1 build.
        for (let i = 2; i <= 5; i++) {
            platform.createBuild({ buildId: `bg360_bld_acme_${i}`, workspaceId: 2, appId: 101 });
        }

        // 6th build attempt must be rejected
        const check = platform.canTriggerBuild(2);
        assert.equal(check.allowed, false);
        assert.equal(check.error, 'build_quota_exceeded');
    });

    test('Suspension: Suspended workspace CANNOT create apps or trigger builds', () => {
        const ws = platform.createWorkspace({ id: 4, name: 'Suspended Corp', slug: 'suspended-corp', ownerEmail: 'bad@actor.com', planId: 1, status: 'suspended' });
        
        const canApp = platform.canCreateApp(ws.id);
        assert.equal(canApp.allowed, false);
        assert.equal(canApp.error, 'workspace_suspended');

        const canBuild = platform.canTriggerBuild(ws.id);
        assert.equal(canBuild.allowed, false);
        assert.equal(canBuild.error, 'workspace_suspended');
    });

    test('Rate Limiter: Allows 5 requests, blocks 6th within 15-minute window', () => {
        const ipKey = 'rate_auth_192.168.1.50';
        for (let i = 1; i <= 5; i++) {
            assert.equal(platform.checkRateLimit(ipKey, 5, 900000), true, `Attempt ${i} should be allowed`);
        }
        // 6th attempt must fail
        assert.equal(platform.checkRateLimit(ipKey, 5, 900000), false, '6th attempt must be rate-limited');
    });
});
