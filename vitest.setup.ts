import { vi } from 'vitest';

// Mock global functions and objects
global.route = vi.fn().mockReturnValue('');

// Mock window.URL for file download tests
Object.defineProperty(window, 'URL', {
  value: {
    createObjectURL: vi.fn().mockReturnValue('mock-url'),
    revokeObjectURL: vi.fn(),
  },
  writable: true,
});

// Mock document for DOM manipulation tests - only if not already mocked
if (!document.createElement.mockImplementation) {
  Object.defineProperty(document, 'createElement', {
    value: vi.fn().mockReturnValue({
      href: '',
      download: '',
      click: vi.fn(),
    }),
    writable: true,
    configurable: true,
  });
}

// Mock console methods to reduce noise in tests
console.error = vi.fn();
console.warn = vi.fn();
console.log = vi.fn();

// Mock CSRF token
Object.defineProperty(document, 'querySelector', {
  value: vi.fn().mockImplementation((selector) => {
    if (selector === 'meta[name="csrf-token"]') {
      return {
        getAttribute: vi.fn().mockReturnValue('mock-csrf-token'),
      };
    }
    return null;
  }),
  writable: true,
  configurable: true,
}); 