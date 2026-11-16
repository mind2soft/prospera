import { describe, expect, it } from 'vitest';
import {
    createKeepaliveBudget,
    KEEPALIVE_BODY_LIMIT_BYTES,
    utf8ByteLength,
} from '@/lib/unloadTransport';

describe('keepalive body budget', () => {
    it('reserves a body that fits the limit on its own', () => {
        const budget = createKeepaliveBudget();

        expect(budget.reserve(1024)).toBe(true);
        expect(budget.outstanding()).toBe(1024);
    });

    it('refuses a body larger than the limit itself', () => {
        const budget = createKeepaliveBudget(100);

        expect(budget.reserve(101)).toBe(false);
        expect(budget.outstanding()).toBe(0);
    });

    it('refuses a body that fits alone but exceeds the outstanding sum', () => {
        const budget = createKeepaliveBudget(100);

        expect(budget.reserve(60)).toBe(true);
        // Individually small, but 60 + 50 > 100: the browser counts the
        // bodies of all keepalive requests still in flight together.
        expect(budget.reserve(50)).toBe(false);
        expect(budget.outstanding()).toBe(60);
    });

    it('frees the budget when a request settles', () => {
        const budget = createKeepaliveBudget(100);

        budget.reserve(60);
        budget.release(60);

        expect(budget.outstanding()).toBe(0);
        expect(budget.reserve(100)).toBe(true);
    });

    it('never counts below zero', () => {
        const budget = createKeepaliveBudget(100);

        budget.release(5);

        expect(budget.outstanding()).toBe(0);
    });

    it('defaults to the browser quota', () => {
        expect(KEEPALIVE_BODY_LIMIT_BYTES).toBe(64 * 1024);

        const budget = createKeepaliveBudget();

        expect(budget.reserve(64 * 1024)).toBe(true);
        budget.release(64 * 1024);
        expect(budget.reserve(64 * 1024 + 1)).toBe(false);
    });
});

describe('utf8ByteLength', () => {
    it('counts ASCII bytes one per character', () => {
        expect(utf8ByteLength('abc')).toBe(3);
    });

    it('counts multi-byte characters by their UTF-8 encoding', () => {
        // é is 2 bytes, € is 3, "𝄞" is 4.
        expect(utf8ByteLength('é')).toBe(2);
        expect(utf8ByteLength('€')).toBe(3);
        expect(utf8ByteLength('𝄞')).toBe(4);
        expect(utf8ByteLength('aé€𝄞')).toBe(10);
    });
});
