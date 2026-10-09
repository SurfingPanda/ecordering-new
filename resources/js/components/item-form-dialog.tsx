import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { CheckCircle2, Loader2, RefreshCw } from 'lucide-react';

import { Field } from '@/components/store-form-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { catalogLabel, catalogs } from '@/lib/charts';
import { field, selectCls } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { ItemSource, RetailGroup } from '@/types';

type BarcodeStatus = 'idle' | 'invalid' | 'checking' | 'available' | 'taken';

interface BarcodeOwner {
    product_code: string;
    description: string;
    archived: boolean;
}

interface DuplicateOwner {
    product_code: string;
    description: string;
    archived: boolean;
}

/** Asks the server (debounced) whether another item already uses this product code / description. */
function useDuplicate(field: 'product_code' | 'description', value: string, source: ItemSource) {
    const [owner, setOwner] = useState<DuplicateOwner | null>(null);
    const [checking, setChecking] = useState(false);

    useEffect(() => {
        const v = value.trim();
        setOwner(null);
        if (v === '') return setChecking(false);

        let stale = false;
        setChecking(true);
        const timer = setTimeout(async () => {
            try {
                const qs = new URLSearchParams({ field, value: v, source });
                const res = await fetch(`/retails/duplicate/check?${qs}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const body = await res.json();
                if (!stale) setOwner(body.taken ? body.used_by : null);
            } catch {
                /* can't check right now; saving still verifies it */
            } finally {
                if (!stale) setChecking(false);
            }
        }, 350);

        return () => {
            stale = true;
            clearTimeout(timer);
        };
    }, [field, value, source]);

    return { owner, checking };
}

/** Admin-only: add an item to the catalog. Every field is required. Categories come from Admin > Categories, per catalog. */
export default function ItemFormDialog({
    open,
    defaultSource,
    categories,
    onClose,
}: {
    open: boolean;
    defaultSource: ItemSource;
    categories: Record<ItemSource, string[]>;
    onClose: () => void;
}) {
    const form = useForm({
        source: defaultSource,
        product_code: '',
        description: '',
        barcode: '',
        category: '',
        retail_group: 'regular_product' as RetailGroup,
    });

    const [generating, setGenerating] = useState(false);
    const [barcodeProblem, setBarcodeProblem] = useState<string | null>(null);
    const [status, setStatus] = useState<BarcodeStatus>('idle');
    const [owner, setOwner] = useState<BarcodeOwner | null>(null);
    const latestCheck = useRef(0); // ignore answers that arrive after the barcode has changed again

    /** Asks the server for a fresh, unused 13-digit barcode. */
    const generateBarcode = useCallback(async () => {
        setGenerating(true);
        setBarcodeProblem(null);
        try {
            const res = await fetch('/retails/barcode', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(String(res.status));
            const { barcode } = await res.json();
            form.setData('barcode', barcode);
            form.clearErrors('barcode');
        } catch {
            setBarcodeProblem('Couldn’t generate a barcode. Press the arrows again.');
        } finally {
            setGenerating(false);
        }
    }, []);

    // every time the form opens it already has a barcode in it
    useEffect(() => {
        if (open) generateBarcode();
    }, [open, generateBarcode]);

    // Whenever the barcode changes (typed, pasted or generated), ask the server whether another item already uses it.
    useEffect(() => {
        const code = form.data.barcode;
        const ticket = ++latestCheck.current;
        setOwner(null);

        if (code === '') return setStatus('idle');
        if (!/^\d{13}$/.test(code)) return setStatus('invalid');

        setStatus('checking');
        const timer = setTimeout(async () => {
            try {
                const res = await fetch(`/retails/barcode/check?barcode=${code}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const body = await res.json();
                if (ticket !== latestCheck.current) return; // a newer barcode is already being checked
                setOwner(body.used_by);
                setStatus(body.available ? 'available' : 'taken');
            } catch {
                if (ticket === latestCheck.current) setStatus('idle'); // can't check right now; saving still verifies it
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [form.data.barcode]);

    const codeDup = useDuplicate('product_code', form.data.product_code, form.data.source);
    const descDup = useDuplicate('description', form.data.description, form.data.source);
    const codeMessage = codeDup.owner ? `Already used by ${codeDup.owner.product_code} — ${codeDup.owner.description}${codeDup.owner.archived ? ' (archived item)' : ''}.` : undefined;
    const descMessage = descDup.owner ? `An item with this description already exists: ${descDup.owner.product_code}${descDup.owner.archived ? ' (archived item)' : ''}.` : undefined;

    const options = categories[form.data.source] ?? [];
    const duplicateMessage =
        status === 'taken' && owner ? `Already used by ${owner.product_code} — ${owner.description}${owner.archived ? ' (archived item)' : ''}. Choose a different barcode.` : undefined;

    /** Every field is required: say exactly what is missing instead of sending an incomplete item. */
    const findMissing = () => {
        const d = form.data;
        const missing: Partial<Record<keyof typeof d, string>> = {};

        if (!d.product_code.trim()) missing.product_code = 'Enter a product code.';
        if (!d.description.trim()) missing.description = 'Enter a description.';
        if (!d.barcode) missing.barcode = 'A barcode is required. Press the arrows in the field to generate one.';
        else if (!/^\d{13}$/.test(d.barcode)) missing.barcode = 'The barcode must be exactly 13 digits.';
        if (!d.category) missing.category = options.length === 0 ? 'This catalog has no categories yet. Add one first.' : 'Please choose a category.';

        return missing;
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (status === 'taken' || codeDup.owner || descDup.owner) return;

        const missing = findMissing();
        if (Object.keys(missing).length > 0) {
            form.clearErrors();
            form.setError(missing as Record<string, string>);
            const first = (['product_code', 'description', 'barcode', 'category'] as const).find((k) => missing[k]);
            if (first) document.getElementById(first)?.focus();
            return;
        }

        form.post('/retails', { preserveScroll: true, onSuccess: onClose });
    };

    const text = (k: 'product_code' | 'description') => ({
        id: k,
        value: form.data[k],
        onChange: (e: React.ChangeEvent<HTMLInputElement>) => {
            form.setData(k, e.target.value);
            form.clearErrors(k);
        },
        'aria-invalid': !!form.errors[k],
        'aria-required': true,
        className: field,
    });

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-xl">
                <DialogTitle>Add item</DialogTitle>
                <DialogDescription>
                    All fields are required. New items appear on every store’s order sheet straight away.
                </DialogDescription>

                <form onSubmit={submit} noValidate className="mt-5 grid gap-4 sm:grid-cols-2">
                    <Field label="Product code" id="product_code" error={codeMessage ?? form.errors.product_code} hint={codeDup.checking ? 'Checking…' : 'Must be unique'} required>
                        <Input {...text('product_code')} autoFocus />
                    </Field>
                    <Field label="Catalog" id="source" error={form.errors.source} required>
                        <select
                            id="source"
                            className={cn(selectCls, 'w-full')}
                            value={form.data.source}
                            onChange={(e) => {
                                // each catalog has its own categories, so a category picked for the other one no longer applies
                                form.setData((d) => ({ ...d, source: e.target.value as ItemSource, category: '' }));
                                form.clearErrors('category');
                            }}
                        >
                            {catalogs.map((s) => (
                                <option key={s} value={s}>
                                    {catalogLabel[s]}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Description" id="description" error={descMessage ?? form.errors.description} required className="sm:col-span-2">
                        <Input {...text('description')} />
                    </Field>

                    <Field label="Barcode" id="barcode" error={duplicateMessage ?? form.errors.barcode ?? barcodeProblem ?? undefined} required className="sm:col-span-2">
                        <div className="relative">
                            <Input
                                id="barcode"
                                inputMode="numeric"
                                maxLength={13}
                                autoComplete="off"
                                value={generating ? '' : form.data.barcode}
                                placeholder={generating ? 'Generating…' : '13 digits'}
                                onChange={(e) => {
                                    form.setData('barcode', e.target.value.replace(/\D/g, '').slice(0, 13));
                                    form.clearErrors('barcode');
                                }}
                                aria-invalid={status === 'taken' || !!form.errors.barcode}
                                aria-required
                                aria-describedby="barcode-status"
                                className={cn(field, 'pr-11 font-mono tracking-widest', status === 'taken' && 'border-destructive')}
                            />
                            <button
                                type="button"
                                onClick={generateBarcode}
                                disabled={generating}
                                aria-label="Generate a new barcode"
                                title="Generate a new barcode"
                                className="absolute right-1 top-1/2 grid size-7 -translate-y-1/2 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted hover:text-secondary disabled:opacity-60"
                            >
                                <RefreshCw className={cn('size-4', generating && 'animate-spin')} />
                            </button>
                        </div>
                        <p id="barcode-status" className="min-h-4 text-xs" aria-live="polite">
                            {status === 'checking' && (
                                <span className="inline-flex items-center gap-1 text-muted-foreground">
                                    <Loader2 className="size-3 animate-spin" /> Checking the barcode…
                                </span>
                            )}
                            {status === 'available' && (
                                <span className="inline-flex items-center gap-1 font-medium text-[#16683a]">
                                    <CheckCircle2 className="size-3.5" /> Barcode is available
                                </span>
                            )}
                            {status === 'invalid' && <span className="text-muted-foreground">A barcode has exactly 13 digits ({form.data.barcode.length}/13).</span>}
                            {status === 'idle' && !generating && <span className="text-muted-foreground">Enter 13 digits, or press the arrows to generate one.</span>}
                        </p>
                    </Field>

                    <Field
                        label="Category"
                        id="category"
                        error={form.errors.category}
                        required
                        hint={options.length === 0 ? undefined : `${catalogLabel[form.data.source]} categories`}
                    >
                        <select
                            id="category"
                            className={cn(selectCls, 'w-full', form.errors.category && 'border-destructive')}
                            value={form.data.category}
                            onChange={(e) => {
                                form.setData('category', e.target.value);
                                form.clearErrors('category');
                            }}
                            aria-invalid={!!form.errors.category}
                            aria-required
                        >
                            <option value="">{options.length === 0 ? 'No categories yet' : 'Select a category'}</option>
                            {options.map((c) => (
                                <option key={c} value={c}>
                                    {c}
                                </option>
                            ))}
                        </select>
                        {options.length === 0 && (
                            <p className="text-xs text-muted-foreground">
                                <Link href="/admin/categories" className="font-medium text-secondary hover:underline">
                                    Add a category
                                </Link>{' '}
                                for this catalog first.
                            </p>
                        )}
                    </Field>
                    <Field label="Retail group" id="retail_group" error={form.errors.retail_group} required>
                        <select id="retail_group" className={cn(selectCls, 'w-full')} value={form.data.retail_group} onChange={(e) => form.setData('retail_group', e.target.value as RetailGroup)}>
                            <option value="regular_product">Regular Product</option>
                            <option value="non_product">Non-product</option>
                        </select>
                    </Field>

                    <div className="flex justify-end gap-2 border-t border-border pt-4 sm:col-span-2">
                        <Button type="button" variant="ghost" size="sm" className="border border-border" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary" size="sm" disabled={form.processing || generating || status === 'taken' || !!codeDup.owner || !!descDup.owner}>
                            {form.processing ? 'Adding…' : 'Add item'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
