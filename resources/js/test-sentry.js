// Test script to verify Sentry frontend integration
// This file can be removed after testing

import * as Sentry from '@sentry/vue';

// Test function to trigger a frontend error for Sentry testing
export function testSentryError() {
    try {
        // Intentional error to test Sentry
        throw new Error('Test error for Sentry frontend tracking');
    } catch (error) {
        // Manually capture the error
        Sentry.captureException(error);
        console.log('Test error sent to Sentry:', error.message);
    }
}

// Test function to add custom breadcrumb
export function testSentryBreadcrumb() {
    Sentry.addBreadcrumb({
        message: 'User performed a test action',
        category: 'user',
        level: 'info',
        data: {
            component: 'test-sentry',
            action: 'breadcrumb-test'
        }
    });
    console.log('Test breadcrumb added to Sentry');
}

// Auto-run tests if this file is loaded directly
if (import.meta.env.DEV) {
    console.log('Sentry test functions available: testSentryError(), testSentryBreadcrumb()');
}