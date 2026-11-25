import { afterEach, describe, expect, test } from 'bun:test';
import { useToast } from '../../resources/js/support/toast.js';

const toast = useToast();

afterEach(() => {
    toast.clear();
});

describe('toast store', () => {
    test('menambahkan notifikasi success dan danger dengan atribut lengkap', () => {
        const id = toast.success({
            title: 'Laporan terkirim',
            description: 'Diteruskan ke unit BUSDEV',
            actionLabel: 'Lacak progres',
            actionUrl: 'https://od.test/track/DOK-1',
            duration: 0,
        });

        expect(toast.toasts).toHaveLength(1);
        expect(toast.toasts[0]).toMatchObject({
            id,
            color: 'success',
            title: 'Laporan terkirim',
            description: 'Diteruskan ke unit BUSDEV',
            actionLabel: 'Lacak progres',
            actionUrl: 'https://od.test/track/DOK-1',
        });

        toast.danger({ title: 'Gagal mengirim', duration: 0 });
        expect(toast.toasts).toHaveLength(2);
        expect(toast.toasts[1].color).toBe('danger');
    });

    test('deduplikasi mengganti toast lama dengan dedupeKey yang sama', () => {
        toast.success({ dedupeKey: 'ticket:1', title: 'Pertama', duration: 0 });
        toast.success({ dedupeKey: 'ticket:1', title: 'Diperbarui', duration: 0 });

        expect(toast.toasts).toHaveLength(1);
        expect(toast.toasts[0].title).toBe('Diperbarui');
    });

    test('membatasi tumpukan maksimal 3 toast dan mendukung dismiss', () => {
        const firstId = toast.info({ title: 'Satu', duration: 0 });
        const secondId = toast.info({ title: 'Dua', duration: 0 });
        toast.info({ title: 'Tiga', duration: 0 });
        toast.info({ title: 'Empat', duration: 0 });

        expect(toast.toasts.map((item) => item.title)).toEqual(['Dua', 'Tiga', 'Empat']);
        expect(toast.toasts.some((item) => item.id === firstId)).toBe(false);

        toast.dismiss(secondId);
        expect(toast.toasts.map((item) => item.title)).toEqual(['Tiga', 'Empat']);
    });

    test('pause dan resume menjaga sisa waktu tanpa menutup toast sebelum waktunya', async () => {
        const id = toast.success({ title: 'Sementara', duration: 500 });
        expect(toast.toasts[0].paused).toBe(false);

        toast.pause(id);
        expect(toast.toasts[0].paused).toBe(true);
        expect(toast.toasts[0].remainingMs).toBeGreaterThanOrEqual(400);

        await new Promise((resolve) => setTimeout(resolve, 60));
        expect(toast.toasts).toHaveLength(1);

        toast.resume(id);
        expect(toast.toasts[0].paused).toBe(false);
    });
});
