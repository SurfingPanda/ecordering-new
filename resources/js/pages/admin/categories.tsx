import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';

import { useConfirm } from '@/components/confirm-dialog';
import { Field } from '@/components/store-form-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { catalogColor, catalogLabel, catalogs } from '@/lib/charts';
import { field, panel } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { ItemSource } from '@/types';

interface CategoryRow {
    id: number;
    source: ItemSource;
    name: string;
    items_count: number;
}

interface Props {
    categories: Record<ItemSource, CategoryRow[]>;
}

const sources: ItemSource[] = catalogs;

export default function Categories({ categories }: Props) {
    const [renaming, setRenaming] = useState<CategoryRow | null>(null);

    const [confirm, confirmDialog] = useConfirm();

    const remove = (c: CategoryRow) =>
        confirm({
            title: `Delete ${c.name}?`,
            description: `This removes the category from the ${catalogLabel[c.source]} catalog.`,
            note:
                c.items_count > 0 ? (
                    <>
                        <strong>
                            {c.items_count} {c.items_count === 1 ? 'item uses' : 'items use'} this category
                        </strong>{' '}
                        and will become uncategorized. Past orders keep the category they were placed with.
                    </>
                ) : undefined,
            tone: 'danger',
            confirmLabel: 'Delete category',
            onConfirm: () => router.delete(`/admin/categories/${c.id}`, { preserveScroll: true }),
        });

    return (
        <AppLayout title="Categories">
            <p className="mb-4 max-w-2xl text-[13px] text-muted-foreground">
                Each catalog has its own categories. They are what you pick from when adding an item, so an item can only be filed under a category of its own catalog.
            </p>

            <div className="grid items-start gap-5 lg:grid-cols-2 2xl:grid-cols-4">
                {sources.map((source) => (
                    <CatalogColumn key={source} source={source} rows={categories[source] ?? []} onRename={setRenaming} onDelete={remove} />
                ))}
            </div>

            {confirmDialog}
            <RenameDialog key={renaming ? renaming.id : 'closed'} category={renaming} onClose={() => setRenaming(null)} />
        </AppLayout>
    );
}

function CatalogColumn({ source, rows, onRename, onDelete }: { source: ItemSource; rows: CategoryRow[]; onRename: (c: CategoryRow) => void; onDelete: (c: CategoryRow) => void }) {
    const form = useForm({ source, name: '' });

    const add = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/categories', { preserveScroll: true, onSuccess: () => form.reset('name') });
    };

    return (
        <section className={panel}>
            <div className="flex items-center gap-2 border-b border-border px-4 py-3">
                <span className="size-2.5 rounded-[3px]" style={{ background: catalogColor[source] }} />
                <h2 className="text-sm font-semibold">{catalogLabel[source]}</h2>
                <span className="ml-auto text-xs text-muted-foreground">
                    {rows.length} {rows.length === 1 ? 'category' : 'categories'}
                </span>
            </div>

            <form onSubmit={add} noValidate className="border-b border-border p-4">
                <Field label={`New ${catalogLabel[source]} category`} id={`name-${source}`} error={form.errors.name}>
                    <div className="flex gap-2">
                        <Input
                            id={`name-${source}`}
                            className={cn(field, 'uppercase')}
                            placeholder={source === 'warehouse' ? 'e.g. CLEANING SUPPLIES' : source === 'bw_products' ? 'e.g. BW BREADS' : `e.g. ${catalogLabel[source].toUpperCase()}`}
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            aria-invalid={!!form.errors.name}
                        />
                        <Button type="submit" variant="secondary" size="sm" className="h-9 shrink-0" disabled={form.processing || form.data.name.trim() === ''}>
                            <Plus /> Add
                        </Button>
                    </div>
                </Field>
            </form>

            {rows.length === 0 ? (
                <p className="px-4 py-10 text-center text-sm text-muted-foreground">No {catalogLabel[source]} categories yet. Add the first one above.</p>
            ) : (
                <ul className="divide-y divide-border/70">
                    {rows.map((c) => (
                        <li key={c.id} className="flex items-center gap-3 px-4 py-2.5 text-[13px]">
                            <span className="min-w-0 flex-1 truncate font-medium">{c.name}</span>
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {c.items_count} {c.items_count === 1 ? 'item' : 'items'}
                            </span>
                            <Button variant="ghost" size="sm" onClick={() => onRename(c)} aria-label={`Rename ${c.name}`}>
                                <Pencil />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => onDelete(c)}
                                title={`Delete ${c.name}`}
                                aria-label={`Delete ${c.name}`}
                                className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function RenameDialog({ category, onClose }: { category: CategoryRow | null; onClose: () => void }) {
    const form = useForm({ name: category?.name ?? '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!category) return;
        form.put(`/admin/categories/${category.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open={category !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogTitle>Rename category</DialogTitle>
                <DialogDescription>
                    {category && category.items_count > 0
                        ? `The ${category.items_count} ${category.items_count === 1 ? 'item' : 'items'} in it will move to the new name. Past orders keep the name they were placed with.`
                        : 'No items use this category yet.'}
                </DialogDescription>
                <form onSubmit={submit} noValidate className="mt-5 space-y-4">
                    <Field label="Category name" id="rename" error={form.errors.name}>
                        <Input id="rename" className={cn(field, 'uppercase')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                    </Field>
                    <div className="flex justify-end gap-2 border-t border-border pt-4">
                        <Button type="button" variant="ghost" size="sm" className="border border-border" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary" size="sm" disabled={form.processing}>
                            Save
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
