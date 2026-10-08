import { FormEvent, useEffect, useRef, useState } from "react";
import { api, ApiError } from "../api";
import { useApp } from "../context";
import { peso } from "../format";
import { Icon } from "../components/Icon";
import { addToCart, lineFromProduct } from "../components/cart";
import { Chip, Notice, Outline } from "../components/ui";
import { can } from "../permissions";
import { findCachedByBarcode, isOfflineError } from "../offline";
import { PurchaseForm } from "./Purchase";
import { SaleForm } from "./Sale";
import { ProductForm } from "./Product";

/** Camera scanning where the browser supports it (BarcodeDetector); typing a barcode always works. */
function Camera({ onCode }: { onCode: (code: string) => void }) {
  const video = useRef<HTMLVideoElement>(null);
  const [state, setState] = useState<"starting" | "on" | "unsupported" | "denied">("starting");

  useEffect(() => {
    const Detector = (window as any).BarcodeDetector;
    if (!Detector || !navigator.mediaDevices?.getUserMedia) { setState("unsupported"); return; }
    let stream: MediaStream | null = null;
    let timer: number | undefined;
    let last = "", lastAt = 0, cancelled = false;
    const detector = new Detector({ formats: ["ean_13", "ean_8", "upc_a", "upc_e", "code_128", "code_39", "itf"] });

    navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
      .then(async (s) => {
        if (cancelled) { s.getTracks().forEach((t) => t.stop()); return; }
        stream = s;
        if (video.current) { video.current.srcObject = s; await video.current.play().catch(() => {}); }
        setState("on");
        timer = window.setInterval(async () => {
          if (!video.current || video.current.readyState < 2) return;
          try {
            const found = await detector.detect(video.current);
            const code = found[0]?.rawValue;
            if (code && (code !== last || Date.now() - lastAt > 2500)) { last = code; lastAt = Date.now(); onCode(code); }
          } catch { /* a frame failed; try the next one */ }
        }, 400);
      })
      .catch(() => setState("denied"));

    return () => { cancelled = true; if (timer) clearInterval(timer); stream?.getTracks().forEach((t) => t.stop()); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="text-center">
      <div className="relative mx-auto grid aspect-square max-w-[280px] place-items-center overflow-hidden rounded-3xl bg-[#142e48]">
        <video ref={video} playsInline muted className={`absolute inset-0 h-full w-full object-cover ${state === "on" ? "opacity-80" : "opacity-0"}`} />
        <div className="h-44 w-44 border-2 border-white/80"><span className="absolute left-[18%] right-[18%] top-1/2 h-0.5 bg-[#53e49f] shadow-[0_0_10px_#53e49f]" /></div>
        <Icon name="scan" size={54} className="absolute text-white/15" />
      </div>
      <h3 className="mt-5 font-bold">Position barcode inside the frame</h3>
      <p className="mt-1 text-sm text-[#7e91a5]">
        {state === "unsupported" ? "Camera scanning is not available in this browser. Type the barcode below." :
         state === "denied" ? "Camera access was blocked. Allow the camera, or type the barcode below." :
         "We'll use it to identify your product."}
      </p>
    </div>
  );
}

export function Scanner({ initialMode = "sale" }: { initialMode?: "sale" | "receiving" }) {
  const app = useApp();
  const canSell = can(app.user.role, "sales.create");
  const canReceive = can(app.user.role, "purchases.create");
  const [mode, setMode] = useState<"sale" | "receiving">(canSell || !canReceive ? initialMode === "receiving" && canReceive ? "receiving" : "sale" : "receiving");
  const [code, setCode] = useState("");
  const [message, setMessage] = useState<{ tone: "info" | "warn" | "error"; text: string } | null>(null);
  const [choices, setChoices] = useState<any[]>([]);
  const [missing, setMissing] = useState<{ barcode: string; suggestion: any } | null>(null);
  const busy = useRef(false);
  const modeRef = useRef(mode);
  modeRef.current = mode;

  const add = (p: any) => {
    if (modeRef.current === "sale") app.setCart((old) => addToCart(old, p));
    else app.setDraft((old) => old.some((l) => l.product_id === p.id) ? old.map((l) => l.product_id === p.id ? { ...l, qty: String(Number(l.qty || 0) + 1) } : l) : [...old, { ...lineFromProduct(p), cost: p.cost_price }]);
    setMessage({ tone: "info", text: `Added ${p.name}${modeRef.current === "sale" ? ` · ${peso(p.selling_price)}` : ""}` });
    setChoices([]); setMissing(null);
  };

  const lookup = async (raw: string) => {
    const barcode = raw.trim();
    if (!barcode || busy.current) return;
    busy.current = true; setMessage(null); setChoices([]); setMissing(null);
    try {
      const r = await api.get(`/barcode/${encodeURIComponent(barcode)}?mode=${modeRef.current}`);
      if (r.products.length === 1) add(r.products[0]);
      else if (r.products.length > 1) { setChoices(r.products); setMessage({ tone: "warn", text: "Two products share this barcode. Choose the right one." }); }
      else if (r.warnings.includes("PRODUCT_INACTIVE")) setMessage({ tone: "warn", text: "That product is deactivated. Reactivate it in Inventory to use it." });
      else { setMissing({ barcode, suggestion: r.suggestion }); setMessage({ tone: "warn", text: r.suggestion ? `Not in your inventory yet. Found: ${r.suggestion.name}.` : "Not in your inventory yet." }); }
      if (r.api_status === "rate_limited") setMessage({ tone: "warn", text: "The product lookup service is busy. You can still enter the details yourself." });
    } catch (err) {
      if (isOfflineError(err)) {
        // No connection: use the products saved on this device (selling only).
        if (modeRef.current === "receiving") setMessage({ tone: "warn", text: "Receiving stock needs an internet connection." });
        else {
          const hits = findCachedByBarcode(app.store.id, barcode);
          if (hits.length === 1) add(hits[0]);
          else if (hits.length > 1) { setChoices(hits); setMessage({ tone: "warn", text: "Two products share this barcode. Choose the right one." }); }
          else setMessage({ tone: "warn", text: "You are offline and this barcode is not in the products saved on this device. Search by name instead." });
        }
      } else setMessage({ tone: "error", text: (err as ApiError).message });
    }
    finally { busy.current = false; setCode(""); }
  };

  const submit = (e: FormEvent) => { e.preventDefault(); void lookup(code); };
  const count = mode === "sale" ? app.cart.length : app.draft.length;
  const review = () => mode === "sale" ? app.openModal("Complete sale", <SaleForm />) : app.openModal("Receive stock", <PurchaseForm />);

  return (
    <div className="space-y-4">
      {canSell && canReceive && <div className="flex justify-center gap-2"><Chip active={mode === "sale"} onClick={() => setMode("sale")}>Sell</Chip><Chip active={mode === "receiving"} onClick={() => setMode("receiving")}>Receive stock</Chip></div>}
      <p className="text-center text-[11px] text-[#7e91a5]">{mode === "sale" ? "Each scan adds one to the sale. Stock goes down when you complete it." : "Each scan adds one to the delivery. Stock goes up when you save it."}</p>
      <Camera onCode={(c) => void lookup(c)} />
      <form onSubmit={submit} className="flex gap-2">
        <input value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" placeholder="Type a barcode" aria-label="Barcode" maxLength={64} className="min-w-0 flex-1 rounded-xl border border-[#d7e2eb] bg-[#fafcfe] px-4 py-3 text-sm outline-none focus:border-[#0879e6]" />
        <button type="submit" className="rounded-full bg-[#0879e6] px-5 text-sm font-bold text-white">Find</button>
      </form>
      {message && <Notice tone={message.tone}>{message.text}</Notice>}
      {choices.length > 0 && <div className="divide-y divide-[#edf1f5] rounded-xl border border-[#e4ebf2]">{choices.map((p) => <button key={p.id} onClick={() => add(p)} className="flex w-full items-center justify-between px-4 py-3 text-left text-sm"><span className="font-semibold">{p.name}</span><span className="font-bold">{peso(p.selling_price)}</span></button>)}</div>}
      {missing && can(app.user.role, "products.manage") && <Outline onClick={() => app.openModal("Add product", <ProductForm prefill={{ barcode: missing.barcode, name: missing.suggestion?.name ?? "", category_name: missing.suggestion?.category ?? "" }} />)}>Add it as a new product</Outline>}
      {count > 0 && <button type="button" onClick={review} className="w-full rounded-full bg-[#0879e6] py-3.5 text-sm font-bold text-white shadow-[0_5px_12px_rgba(8,121,230,.2)]">{mode === "sale" ? `Review sale (${count} ${count === 1 ? "item" : "items"})` : `Review delivery (${count} ${count === 1 ? "item" : "items"})`}</button>}
      <Outline onClick={review}>Enter details manually</Outline>
      {can(app.user.role, "products.manage") && <button type="button" onClick={() => app.openModal("Add product", <ProductForm />)} className="w-full text-center text-xs font-bold text-[#0879e6]">Add a new product</button>}
    </div>
  );
}
