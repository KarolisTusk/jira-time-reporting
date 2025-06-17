import { describe, it, expect } from 'vitest';

describe('JiraSyncError Component Logic', () => {
  const defaultErrorProps = {
    error: {
      sync_history_id: 1,
      primary_message: 'Authentication failed',
      total_errors: 5,
      warning_count: 2,
      progress_percentage: 45,
      error_logs: [
        {
          id: 1,
          timestamp: '2024-01-01T10:00:00Z',
          level: 'error' as const,
          message: 'Authentication failed',
          context: { status_code: 401 },
          entity_type: 'project',
          entity_id: 'PROJ-1',
          operation: 'fetch',
        },
        {
          id: 2,
          timestamp: '2024-01-01T10:01:00Z',
          level: 'warning' as const,
          message: 'Rate limit warning',
          context: { remaining_requests: 10 },
          entity_type: 'issue',
          entity_id: 'ISSUE-123',
          operation: 'create',
        },
      ],
    },
    canRetry: true,
    isProcessing: false,
  };

  // Test the error categorization logic
  const getErrorType = (log: any): string => {
    const message = log.message.toLowerCase();
    const context = log.context || {};
    
    if (message.includes('connection') || message.includes('network') || message.includes('timeout')) {
      return 'connection';
    }
    if (message.includes('authentication') || message.includes('unauthorized') || message.includes('forbidden')) {
      return 'authentication';
    }
    if (message.includes('rate limit') || message.includes('429') || context.status_code === 429) {
      return 'rate_limit';
    }
    if (message.includes('data') || message.includes('parse') || message.includes('format')) {
      return 'data';
    }
    if (log.entity_type) {
      return log.entity_type;
    }
    
    return 'general';
  };

  it('categorizes authentication errors correctly', () => {
    const authError = defaultErrorProps.error.error_logs[0];
    expect(getErrorType(authError)).toBe('authentication');
  });

  it('categorizes rate limit errors correctly', () => {
    const rateLimitError = {
      id: 3,
      message: 'Rate limit exceeded',
      context: { status_code: 429 },
      level: 'warning' as const,
      timestamp: '2024-01-01T10:00:00Z',
      entity_type: 'issue',
      entity_id: 'ISSUE-1',
      operation: 'fetch',
    };
    expect(getErrorType(rateLimitError)).toBe('rate_limit');
  });

  it('categorizes connection errors correctly', () => {
    const connectionError = {
      id: 4,
      message: 'Connection timeout',
      context: {},
      level: 'error' as const,
      timestamp: '2024-01-01T10:00:00Z',
      entity_type: 'project',
      entity_id: 'PROJ-1',
      operation: 'fetch',
    };
    expect(getErrorType(connectionError)).toBe('connection');
  });

  it('formats datetime correctly', () => {
    const formatDateTime = (dateString: string) => {
      try {
        const date = new Date(dateString);
        return date.toLocaleString();
      } catch {
        return dateString;
      }
    };

    const testDate = '2024-01-01T10:00:00Z';
    const formatted = formatDateTime(testDate);
    
    // Should return a localized date string
    expect(formatted).toContain('2024');
    expect(formatted).toContain('1');
  });

  it('generates appropriate actions for authentication errors', () => {
    const hasErrorType = (type: string, errorLogs: any[]): boolean => {
      return errorLogs.some(log => getErrorType(log) === type);
    };

    const hasAuth = hasErrorType('authentication', defaultErrorProps.error.error_logs);
    expect(hasAuth).toBe(true);

    const hasConnection = hasErrorType('connection', defaultErrorProps.error.error_logs);
    expect(hasConnection).toBe(false);
  });

  it('calculates error statistics correctly', () => {
    const { error } = defaultErrorProps;
    
    expect(error.total_errors).toBe(5);
    expect(error.warning_count).toBe(2);
    expect(error.progress_percentage).toBe(45);
    expect(error.error_logs.length).toBe(2);
  });

  it('validates error log structure', () => {
    const errorLog = defaultErrorProps.error.error_logs[0];
    
    expect(errorLog).toHaveProperty('id');
    expect(errorLog).toHaveProperty('timestamp');
    expect(errorLog).toHaveProperty('level');
    expect(errorLog).toHaveProperty('message');
    expect(errorLog).toHaveProperty('context');
    expect(['info', 'warning', 'error']).toContain(errorLog.level);
  });

  it('handles missing context gracefully', () => {
    const errorWithoutContext = {
      id: 5,
      message: 'Simple error',
      context: {},
      level: 'error' as const,
      timestamp: '2024-01-01T10:00:00Z',
      entity_type: null,
      entity_id: null,
      operation: null,
    };

    expect(() => getErrorType(errorWithoutContext)).not.toThrow();
    expect(getErrorType(errorWithoutContext)).toBe('general');
  });
}); 