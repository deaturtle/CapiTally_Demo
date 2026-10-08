import { useApp } from "../context";
import { dateLabel, peso, timeLabel } from "../format";
import { Icon } from "../components/Icon";
import { Notice, Outline } from "../components/ui";
import { removeQueued, syncQueue, updateQueued, useQueue } from "../offline";

/** Sales saved on this device. Failed ones explain why and offer the safe ways to resolve them. */
export function PendingSales() {
  const app = useApp();
  const queue = useQueue(app.store.id);
  const retry = async () => { const r = await syncQueue(app.store.id, app.user.id); if (r.synced > 0) app.refresh(); };

  const act = async (id: string, patch: any) => { updateQueued(app.store.id, id, { status: "waiting", error: undefined, code: undefined, details: undefined, ...patch }); await retry(); };
  const withCurrentPrice = (item: any) => act(item.id, { payload: { ...item.payload, items: item.payload.items.map((i: any) => ({ product_id: i.product_id, quantity: i.quantity })) } });
  const sellAnyway = (item: any) => act(item.id, { payload: { ...item.payload, allow_negative_stock: true } });
  const discard = (id: string) => { if (confirm("Discard this sale? It was never recorded, so the money and stock will not change.")) removeQueued(app.store.id, id); };

  if (queue.length === 0) return <p className="py-6 text-center text-sm text-[#7e91a5]">Nothing is waiting. All sales are uploaded.</p>;
  return (
    <div className="space-y-3">
      <Outline onClick={retry}>Upload now</Outline>
      {queue.map((q) => (
        <div key={q.id} className={`rounded-2xl border p-4 ${q.status === "failed" ? "border-[#f3c6cc] bg-[#fffafa]" : "border-[#e4ebf2] bg-white"}`}>
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0"><p className="truncate text-sm font-semibold">{q.summary}</p><p className="text-[11px] text-[#8495a5]">{dateLabel(q.queuedAt)}, {timeLabel(q.queuedAt)}</p></div>
            <p className="text-sm font-bold">{peso(q.total)}</p>
          </div>
          {q.status === "waiting" && <p className="mt-2 flex items-center gap-2 text-xs text-[#9a5a12]"><Icon name="history" size={14} />{q.userId === app.user.id ? "Waiting to upload" : "Waiting for the cashier who recorded it to log in"}</p>}
          {q.status === "failed" && (
            <div className="mt-3 space-y-2">
              <Notice>{q.error}</Notice>
              <div className="flex flex-wrap gap-2 text-xs font-bold">
                {q.code === "PRICE_CHANGED" && <button onClick={() => withCurrentPrice(q)} className="rounded-full border border-[#0879e6] px-4 py-2 text-[#0879e6]">Sell at the current price</button>}
                {q.code === "INSUFFICIENT_STOCK" && app.user.role === "owner" && app.store.allow_negative_override && <button onClick={() => sellAnyway(q)} className="rounded-full border border-[#e77a22] px-4 py-2 text-[#c2620f]">Record anyway</button>}
                <button onClick={() => act(q.id, {})} className="rounded-full border border-[#0879e6] px-4 py-2 text-[#0879e6]">Try again</button>
                <button onClick={() => discard(q.id)} className="rounded-full border border-[#e3b4bb] px-4 py-2 text-[#c2455a]">Discard</button>
              </div>
            </div>
          )}
        </div>
      ))}
    </div>
  );
}
