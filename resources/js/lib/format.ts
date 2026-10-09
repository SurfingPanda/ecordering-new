const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

export const formatMoney = (n: number) => money.format(n);

/** A plain calendar date (YYYY-MM-DD) without time-zone shifting. */
export const formatDay = (ymd: string) =>
    new Date(ymd + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });

export const formatDate = (iso: string) =>
    new Date(iso).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });

/** "16:00" -> "4:00 PM" */
export const formatTime = (hhmm: string) => {
    const [h, m] = hhmm.split(':').map(Number);
    const d = new Date();
    d.setHours(h, m, 0, 0);
    return d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
};
