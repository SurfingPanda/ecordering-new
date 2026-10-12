import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ArrowRight, Pencil, Plus, Trash2 } from 'lucide-react';

import { useConfirm } from '@/components/confirm-dialog';
import { Field } from '@/components/store-form-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { catalogColor, catalogLabel, catalogs, nf } from '@/lib/charts';
import { field, panel, selectCls, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { ItemSource } from '@/types';

type RuleType = 'code_contains' | 'group';

interface Rule {
    id: number;
    type: RuleType;
    match: string;
    source: ItemSource;
    items: number;
}

interface Props {
    rules: Rule[];
    misfiled: number;
    misfiled_sample: { code: string; name: string; from: ItemSource; to: ItemSource }[];
}

const typeLabel: Record<RuleType, string> = { code_contains: 'Item code contains', group: 'ECPOS group is' };

function CatalogTag({ source }: { source: ItemSource }) {
    return (
        <span className="inline-flex items-center gap-1.5 font-medium">
            <span className="size-2.5 rounded-[3px]" style={{ background: catalogColor[source] }} />
            {catalogLabel[source]}
        </span>
    );
}

/** "Sync Rules": which catalog the items that come from ECPOS are filed in. */
export default function SyncRules({ rules, misfiled, misfiled_sample }: Props) {
    const [editing, setEditing] = useState<Rule | 'new' | null>(null);
    const [confirm, confirmDialog] = useConfirm();

    const remove = (r: Rule) =>
        confirm({
            title: 'Delete this rule?',
            description: (
                <>
                    {typeLabel[r.type]} <strong>{r.match}</strong> → {catalogLabel[r.source]}
                </>
            ),
            note: <>Items already filed stay where they are. New items from ECPOS that matched this rule will go to the next rule that fits, or to BW Products.</>,
            tone: 'danger',
            confirmLabel: 'Delete rule',
            onConfirm: () => router.delete(`/admin/sync-rules/${r.id}`, { preserveScroll: true }),
        });

    const apply = () =>
        confirm({
            title: `Move ${nf.format(misfiled)} ${misfiled === 1 ? 'item' : 'items'} now?`,
            description: 'They go to the catalog their rule says. Past orders keep the catalog they were placed with.',
            confirmLabel: 'Move them',
            onConfirm: () => router.post('/admin/sync-rules/apply', {}, { preserveScroll: true }),
        });

    return (
        <AppLayout
            title="Sync Rules"
            actions={
                <Button variant="secondary" size="sm" onClick={() => setEditing('new')}>
                    <Plus /> Add rule
                </Button>
            }
        >
            <p className="mb-4 max-w-3xl text-[13px] leading-relaxed text-muted-foreground">
                When items come from ECPOS, these rules decide which catalog each one goes in. A rule on the <strong className="text-foreground">item code</strong> wins over a rule on the{' '}
                <strong className="text-foreground">ECPOS group</strong>. Anything that matches no rule goes to <strong className="text-foreground">BW Products</strong>. Items you added by hand are never moved.
            </p>

            {misfiled > 0 && (
                <div role="status" className="mb-4 flex flex-wrap items-center gap-3 rounded-md border border-[#c9d4fa] bg-[#eef2ff] px-4 py-3 text-sm text-[#1f3aa8]">
                    <div className="min-w-0 flex-1">
                        <p className="font-semibold">
                            {nf.format(misfiled)} {misfiled === 1 ? 'item is' : 'items are'} not in the catalog the rules say
                        </p>
                        <p className="mt-0.5 text-xs text-[#1f3aa8]/80">
                            {misfiled_sample.map((m) => `${m.name} (${catalogLabel[m.from]} → ${catalogLabel[m.to]})`).join(', ')}
                            {misfiled > misfiled_sample.length ? ` and ${nf.format(misfiled - misfiled_sample.length)} more` : ''}. The nightly sync moves them too.
                        </p>
                    </div>
                    <Button size="sm" variant="secondary" onClick={apply}>
                        Move them now
                    </Button>
                </div>
            )}

            <section className={cn(panel, 'overflow-hidden')}>
                <div className="no-scrollbar overflow-x-auto">
                    <table className="w-full min-w-[560px] border-collapse">
                        <thead className="bg-secondary text-secondary-foreground">
                            <tr>
                                <th className={th}>When</th>
                                <th className={th}>Value</th>
                                <th className={th}>Goes to</th>
                                <th className={cn(th, 'text-right')}>Items now</th>
                                <th className={cn(th, 'w-24')} />
                            </tr>
                        </thead>
                        <tbody>
                            {rules.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-5 py-12 text-center text-sm text-muted-foreground">
                                        No rules yet, so every item from ECPOS goes to BW Products.
                                    </td>
                                </tr>
                            )}
                            {rules.map((r) => (
                                <tr key={r.id} className="border-b border-border/70 odd:bg-white even:bg-[#f8f9fc]">
                                    <td className={cn(td, 'whitespace-nowrap text-muted-foreground')}>{typeLabel[r.type]}</td>
                                    <td className={cn(td, 'font-mono font-semibold')}>{r.match}</td>
                                    <td className={td}>
                                        <span className="inline-flex items-center gap-2">
                                            <ArrowRight className="size-3.5 text-muted-foreground" />
                                            <CatalogTag source={r.source} />
                                        </span>
                                    </td>
                                    <td className={cn(td, 'text-right tabular-nums text-muted-foreground')}>{nf.format(r.items)}</td>
                                    <td className={cn(td, 'whitespace-nowrap text-right')}>
                                        <Button variant="ghost" size="sm" onClick={() => setEditing(r)} aria-label={`Edit rule ${r.match}`}>
                                            <Pencil />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => remove(r)}
                                            aria-label={`Delete rule ${r.match}`}
                                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        >
                                            <Trash2 />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                    "Items now" counts the items from ECPOS that match the rule. Matching ignores capital letters.
                </p>
            </section>

            {confirmDialog}
            <RuleDialog key={editing === null ? 'closed' : editing === 'new' ? 'new' : editing.id} rule={editing} onClose={() => setEditing(null)} />
        </AppLayout>
    );
}

function RuleDialog({ rule, onClose }: { rule: Rule | 'new' | null; onClose: () => void }) {
    const existing = rule && rule !== 'new' ? rule : null;
    const form = useForm<{ type: RuleType; match: string; source: ItemSource }>({
        type: existing?.type ?? 'code_contains',
        match: existing?.match ?? '',
        source: existing?.source ?? 'warehouse',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (existing) form.put(`/admin/sync-rules/${existing.id}`, opts);
        else form.post('/admin/sync-rules', opts);
    };

    return (
        <Dialog open={rule !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogTitle>{existing ? 'Edit rule' : 'Add a rule'}</DialogTitle>
                <DialogDescription>Items from ECPOS that match go to the catalog you pick.</DialogDescription>
                <form onSubmit={submit} noValidate className="mt-5 space-y-4">
                    <Field label="When" id="rule-type" error={form.errors.type}>
                        <select id="rule-type" className={cn(selectCls, 'w-full')} value={form.data.type} onChange={(e) => form.setData('type', e.target.value as RuleType)}>
                            <option value="code_contains">The item code contains</option>
                            <option value="group">The ECPOS item group is</option>
                        </select>
                    </Field>
                    <Field label={form.data.type === 'code_contains' ? 'Text in the code' : 'Item group'} id="rule-match" error={form.errors.match}>
                        <Input
                            id="rule-match"
                            className={cn(field, 'font-mono uppercase')}
                            placeholder={form.data.type === 'code_contains' ? 'e.g. -SUP-' : 'e.g. STORE SUPPLIES'}
                            value={form.data.match}
                            onChange={(e) => form.setData('match', e.target.value)}
                            aria-invalid={!!form.errors.match}
                            autoFocus
                        />
                    </Field>
                    <Field label="Goes to" id="rule-source" error={form.errors.source}>
                        <select id="rule-source" className={cn(selectCls, 'w-full')} value={form.data.source} onChange={(e) => form.setData('source', e.target.value as ItemSource)}>
                            {catalogs.map((s) => (
                                <option key={s} value={s}>
                                    {catalogLabel[s]}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <div className="flex justify-end gap-2 border-t border-border pt-4">
                        <Button type="button" variant="ghost" size="sm" className="border border-border" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary" size="sm" disabled={form.processing || form.data.match.trim().length < 2}>
                            {existing ? 'Save rule' : 'Add rule'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
