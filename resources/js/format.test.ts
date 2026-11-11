import { expect, it } from 'vitest';
import { formatCommission } from './format';

it('formats the decimal string exactly, and never shows NaN', () => {
    expect(formatCommission('1500')).toBe('1,500.00');
    expect(formatCommission('-1234.5')).toBe('-1,234.50');
    expect(formatCommission('9007199254740993.10')).toBe('9,007,199,254,740,993.10'); // 2^53 + 1: a float would round it
    expect(formatCommission('abc')).toBe('—');
});
